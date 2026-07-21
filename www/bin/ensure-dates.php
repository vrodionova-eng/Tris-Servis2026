<?php
// Cron worker: ensure +30 working days ahead in Google Sheets.
// Schedule: 0 0 * * * /usr/bin/php /var/www/Tris-Servis2026/bin/ensure-dates.php
//
// Logic:
//   1. Find last date row in column A.
//   2. Fill forward to today + 30 calendar days, skipping Sat/Sun.
//   3. Every 5 working days → light-grey week separator row.
//   4. On month change → darker grey month separator row.
//   5. Existing rows are never touched — only appends missing ones.
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/lib.php';
require_once __DIR__ . '/../api/sheets.php';
require_once __DIR__ . '/../api/sync.php';

$LOG_DIR   = DATA_ROOT . '/cron-logs';
$LOCK_FILE = DATA_ROOT . '/ensure-dates.lock';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0700, true);
$LOG_FILE = $LOG_DIR . '/ensure-dates-' . date('Y-m-d') . '.log';

function elog(string $s): void
{
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, '[' . date('c') . '] ' . $s . "\n", FILE_APPEND);
}

$lock = @fopen($LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

if (!is_file(GOOGLE_SA_FILE)) {
    elog('SKIP: Google SA key not found');
    exit(0);
}

$started = microtime(true);
elog('=== start ===');

try {
    ensureDates();
} catch (Throwable $e) {
    elog('EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

elog('=== end (' . round(microtime(true) - $started, 2) . 's) ===');

// ─────────────────────────────────────────────────────────────────────────────

function ensureDates(): void
{
    $sheets    = new GoogleSheets(SHEETS_ID);
    $columnData = $sheets->readColumnA();
    $dateToRow  = $columnData['dates'];  // 'DD.MM.YYYY' => rowNum
    $monthToRow = $columnData['months']; // 'YYYY-MM'    => rowNum

    elog('Existing dates: ' . count($dateToRow) . ', month rows: ' . count($monthToRow));

    // Target: today + 30 calendar days
    $today  = strtotime('today');
    $target = strtotime('+30 days', $today);

    // Find last existing date or start from today
    $lastDateTs = findLastDateTs($dateToRow);
    $current    = $lastDateTs !== null ? $lastDateTs + 86400 : $today;

    // Skip weekends for starting point
    while (isWeekend($current)) $current += 86400;

    // Count working days since last separator (week or month)
    $workDayCounter = countWorkDaysSinceLastSeparator($sheets, $dateToRow, $monthToRow);
    $lastMonth      = $lastDateTs !== null ? (int)date('n', $lastDateTs) : (int)date('n', $today);

    $inserted = 0;
    while ($current <= $target) {
        if (isWeekend($current)) {
            $current += 86400;
            continue;
        }

        $dateStr  = date('d.m.Y', $current);
        $monthNum = (int)date('n', $current);
        $yearNum  = (int)date('Y', $current);
        $monthKey = sprintf('%04d-%02d', $yearNum, $monthNum);

        // Month separator on month change
        if ($monthNum !== $lastMonth && !isset($monthToRow[$monthKey])) {
            $pos = findInsertRow($dateStr, array_merge($dateToRow, $monthToRow));
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertMonthRow(ruMonthLabel($dateStr), $pos);
            $monthToRow[$monthKey] = $pos;
            $lastMonth = $monthNum;
            $workDayCounter = 0;
            elog("Month separator: " . ruMonthLabel($dateStr) . " → row $pos");
            $inserted++;
        }
        // Week separator every 5 working days
        elseif ($workDayCounter > 0 && $workDayCounter % 5 === 0 && !isset($dateToRow[$dateStr])) {
            $pos = findInsertRow($dateStr, array_merge($dateToRow, $monthToRow));
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertWeekRow($pos);
            elog("Week separator → row $pos");
            $inserted++;
        }

        // Date row
        if (!isset($dateToRow[$dateStr])) {
            $pos = findInsertRow($dateStr, array_merge($dateToRow, $monthToRow));
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertDateRow($dateStr, $pos);
            $dateToRow[$dateStr] = $pos;
            $workDayCounter++;
            elog("Date: $dateStr → row $pos");
            $inserted++;
        }

        $current += 86400;
    }

    elog("Inserted rows: $inserted");
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function findLastDateTs(array $dateToRow): ?int
{
    $maxTs = null;
    foreach (array_keys($dateToRow) as $d) {
        $p = explode('.', $d);
        if (count($p) !== 3) continue;
        $ts = mktime(0, 0, 0, (int)$p[1], (int)$p[0], (int)$p[2]);
        if ($maxTs === null || $ts > $maxTs) $maxTs = $ts;
    }
    return $maxTs;
}

function countWorkDaysSinceLastSeparator(GoogleSheets $sheets, array $dateToRow, array $monthToRow): int
{
    if (empty($dateToRow)) return 0;

    // Walk backwards from the last row; stop at any separator row
    $allRows = array_merge(array_values($dateToRow), array_values($monthToRow));
    $lastRow = max($allRows);

    // We need to read the actual sheet to detect week separators.
    // Simpler approach: count dates from the end until we hit a gap > 1 row
    // or a month separator. Week separators are blank rows we can't distinguish
    // from month rows via readColumnA alone, so we read raw values.
    $sorted = $dateToRow;
    arsort($sorted); // highest row first

    $count = 0;
    $prevRow = null;
    foreach ($sorted as $date => $row) {
        if ($prevRow !== null && $prevRow - $row > 1) {
            break; // gap = separator row(s)
        }
        // Check if this row is a month separator
        $p = explode('.', $date);
        $mk = $p[2] . '-' . $p[1];
        if (isset($monthToRow[$mk]) && $monthToRow[$mk] === $row) break;

        $count++;
        $prevRow = $row;
    }
    return $count;
}

function shiftRows(array &$dateToRow, array &$monthToRow, int $pos): void
{
    foreach ($dateToRow as &$r) { if ($r >= $pos) $r++; }
    unset($r);
    foreach ($monthToRow as &$r) { if ($r >= $pos) $r++; }
    unset($r);
}

function isWeekend(int $ts): bool
{
    $dow = (int)date('w', $ts);
    return $dow === 0 || $dow === 6;
}
