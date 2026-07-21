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

    // Determine position within current working week
    // by looking at the last existing date's day-of-week
    $lastDateTs = findLastDateTs($dateToRow);
    $lastDow    = $lastDateTs !== null ? (int)date('w', $lastDateTs) : null; // 0=Sun, 1=Mon..5=Fri

    // workDayCounter: how many working days already passed in current week block
    // If last day was Friday(5) or weekend — counter starts at 0
    // Otherwise counter = day of week number (Mon=1, Tue=2...)
    $workDayCounter = ($lastDow !== null && $lastDow >= 1 && $lastDow <= 4) ? $lastDow : 0;
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
        } elseif ($workDayCounter >= 5 && !isset($dateToRow[$dateStr])) {
            $pos = findInsertRow($dateStr, array_merge($dateToRow, $monthToRow));
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertWeekRow($pos);
            $workDayCounter = 0;
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
