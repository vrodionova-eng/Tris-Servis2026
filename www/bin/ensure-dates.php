<?php
// Cron worker: ensure +30 working days ahead in Google Sheets with separators.
// Schedule: 0 0 * * * /usr/bin/php /var/www/Tris-Servis2026/bin/ensure-dates.php
//
// Logic (Monday-based):
//   1. Backfill: for every existing Monday date — make sure the row directly
//      above it is an empty separator row (no date). If not — insert grey row.
//   2. Month separators: before the first date of each month (if missing).
//   3. Forward fill: append new dates from last existing date up to today+30,
//      skipping weekends; before each Monday insert a week separator first.
//
// Separator detection: a separator row = row with EMPTY column A
// (month separators have "Июль 2026" text and are skipped separately).
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
    $inserted = 0;

    // ── Pass 1: backfill separators between existing dates ───────────────────
    // Sort existing dates chronologically
    $sortedDates = array_keys($dateToRow);
    usort($sortedDates, fn($a, $b) => dateToTs($a) <=> dateToTs($b));

    foreach ($sortedDates as $dateStr) {
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

        // Week separator: before each Monday, the row above must be empty
        // (i.e. there must be a gap between this Monday and the previous date row).
        if ($dow === 1) {
            $thisRow = $dateToRow[$dateStr] ?? null;
            // Row directly above this Monday
            $aboveRow = $thisRow !== null ? $thisRow - 1 : null;
            // Is any date or month label sitting in $aboveRow?
            $aboveIsDate  = $aboveRow !== null && in_array($aboveRow, $dateToRow, true);
            $aboveIsMonth = $aboveRow !== null && in_array($aboveRow, $monthToRow, true);

            if ($thisRow !== null && !$aboveIsDate && !$aboveIsMonth) {
                // Row above is empty → separator already exists, nothing to do
                continue;
            }
            if ($thisRow !== null && $aboveIsDate && !$aboveIsMonth) {
                // Row above holds another date (Friday) — insert separator between
                $pos = $thisRow; // push Monday down
                shiftRows($dateToRow, $monthToRow, $pos);
                $sheets->insertWeekRow($pos);
                elog("Week separator (backfill) → row $pos");
                $inserted++;
            }
            // If above is a month row — month separator already separates weeks, skip
        }
    }

    // ── Pass 2: forward fill new dates up to target ───────────────────────────
    $lastDateTs = findLastDateTs($dateToRow);
    $current    = $lastDateTs !== null ? $lastDateTs + 86400 : $today;
    while (isWeekend($current)) $current += 86400;

    $lastMonth = $lastDateTs !== null ? (int)date('n', $lastDateTs) : (int)date('n', $today);

    while ($current <= $target) {
        if (isWeekend($current)) {
            $current += 86400;
            continue;
        }

        $dateStr  = date('d.m.Y', $current);
        $dow      = (int)date('w', $current);
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
            elog("Month separator: " . ruMonthLabel($dateStr) . " → row $pos");
            $inserted++;
        } elseif ($dow === 1 && !isset($dateToRow[$dateStr])) {
            // Week separator before Monday (unless month separator just inserted)
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
