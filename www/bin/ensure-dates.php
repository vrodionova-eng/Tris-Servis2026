<?php
// Cron worker: ensure +30 working days ahead in Google Sheets with separators.
// Schedule: 0 0 * * * /usr/bin/php /var/www/Tris-Servis2026/bin/ensure-dates.php
//
// Two passes:
//   1. Backfill: walk existing dates top→bottom, insert missing week/month
//      separators between them.
//   2. Forward fill: append new dates from last existing date up to today+30,
//      inserting separators as needed.
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
    $sheets     = new GoogleSheets(SHEETS_ID);
    $columnData = $sheets->readColumnA();
    $dateToRow  = $columnData['dates'];  // 'DD.MM.YYYY' => rowNum
    $monthToRow = $columnData['months']; // 'YYYY-MM'    => rowNum

    elog('Existing dates: ' . count($dateToRow) . ', month rows: ' . count($monthToRow));

    $today  = strtotime('today');
    $target = strtotime('+30 days', $today);

    // ── Pass 1: backfill separators between existing dates ───────────────────
    // Sort existing dates chronologically
    $sortedDates = array_keys($dateToRow);
    usort($sortedDates, fn($a, $b) => dateToTs($a) <=> dateToTs($b));

    $inserted = 0;

    for ($i = 0; $i < count($sortedDates); $i++) {
        $dateStr  = $sortedDates[$i];
        $ts       = dateToTs($dateStr);
        $dow      = (int)date('w', $ts); // 1=Mon .. 5=Fri
        $monthNum = (int)date('n', $ts);
        $yearNum  = (int)date('Y', $ts);
        $monthKey = sprintf('%04d-%02d', $yearNum, $monthNum);

        // Month separator before first date of a new month
        if (!isset($monthToRow[$monthKey])) {
            $pos = findInsertRow($dateStr, array_merge($dateToRow, $monthToRow));
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertMonthRow(ruMonthLabel($dateStr), $pos);
            $monthToRow[$monthKey] = $pos;
            elog("Month separator: " . ruMonthLabel($dateStr) . " → row $pos");
            $inserted++;
        }

        // Week separator before a Monday (dow=1) that follows a Friday (dow=5)
        // We detect this by checking: is this date a Monday, and is there
        // a Friday date immediately before it in the sorted list?
        if ($dow === 1 && $i > 0) {
            $prevTs  = dateToTs($sortedDates[$i - 1]);
            $prevDow = (int)date('w', $prevTs);
            if ($prevDow === 5) {
                // Check no separator already exists between them
                $prevRow = $dateToRow[$sortedDates[$i - 1]] ?? null;
                $thisRow = $dateToRow[$dateStr] ?? null;
                if ($prevRow !== null && $thisRow !== null && $thisRow === $prevRow + 1) {
                    // No gap — need to insert separator
                    $pos = $thisRow; // insert at current date's row, pushing it down
                    shiftRows($dateToRow, $monthToRow, $pos);
                    $sheets->insertWeekRow($pos);
                    elog("Week separator (backfill) → row $pos");
                    $inserted++;
                }
            }
        }
    }

    // ── Pass 2: forward fill new dates up to target ───────────────────────────
    $lastDateTs = findLastDateTs($dateToRow);
    $current    = $lastDateTs !== null ? $lastDateTs + 86400 : $today;
    while (isWeekend($current)) $current += 86400;

    $lastDow = $lastDateTs !== null ? (int)date('w', $lastDateTs) : null;
    $workDayCounter = ($lastDow !== null && $lastDow >= 1 && $lastDow <= 4) ? $lastDow : 0;
    $lastMonth = $lastDateTs !== null ? (int)date('n', $lastDateTs) : (int)date('n', $today);

    while ($current <= $target) {
        if (isWeekend($current)) {
            $current += 86400;
            continue;
        }

        $dateStr  = date('d.m.Y', $current);
        $monthNum = (int)date('n', $current);
        $yearNum  = (int)date('Y', $current);
        $monthKey = sprintf('%04d-%02d', $yearNum, $monthNum);

        // Month separator
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
            // Week separator
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

    elog("Total inserted rows: $inserted");
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function dateToTs(string $d): int
{
    $p = explode('.', $d);
    return count($p) === 3 ? mktime(0, 0, 0, (int)$p[1], (int)$p[0], (int)$p[2]) : 0;
}

function findLastDateTs(array $dateToRow): ?int
{
    $maxTs = null;
    foreach (array_keys($dateToRow) as $d) {
        $ts = dateToTs($d);
        if ($ts && ($maxTs === null || $ts > $maxTs)) $maxTs = $ts;
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
