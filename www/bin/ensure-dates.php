<?php
// Cron worker: add EXACTLY ONE row per run (date or week separator).
// Schedule: 0 0 * * * /usr/bin/php /var/www/Tris-Servis2026/bin/ensure-dates.php
//
// Logic:
//   1. Backfill: ensure every existing Monday has an empty row above it.
//   2. Then, add ONLY ONE new row:
//        - If next workday is Monday AND no separator above → insert grey separator.
//        - Else → insert the next workday's date.
//
// Separator = empty cell in column A (month headers are ignored).
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
    $dateToRow  = $columnData['dates'];
    $monthToRow = $columnData['months'];

    elog('Existing dates: ' . count($dateToRow) . ', month rows: ' . count($monthToRow));

    $inserted = 0;

    // ── Pass 1: Backfill — ensure separators before existing Mondays ───────────
    $sortedDates = array_keys($dateToRow);
    usort($sortedDates, fn($a, $b) => dateToTs($a) <=> dateToTs($b));

    foreach ($sortedDates as $dateStr) {
        $ts       = dateToTs($dateStr);
        $dow      = (int)date('w', $ts);
        $monthNum = (int)date('n', $ts);
        $yearNum  = (int)date('Y', $ts);
        $monthKey = sprintf('%04d-%02d', $yearNum, $monthNum);

        // Month separator
        if (!isset($monthToRow[$monthKey])) {
            $pos = findInsertRow($dateStr, array_merge($dateToRow, $monthToRow));
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertMonthRow(ruMonthLabel($dateStr), $pos);
            $monthToRow[$monthKey] = $pos;
            elog("Month separator: " . ruMonthLabel($dateStr) . " → row $pos");
            $inserted++;
        }

        // Week separator before Monday
        if ($dow === 1) {
            $thisRow = $dateToRow[$dateStr] ?? null;
            if ($thisRow === null) continue;
            $aboveRow = $thisRow - 1;
            $aboveIsDate  = $aboveRow !== null && in_array($aboveRow, $dateToRow, true);
            $aboveIsMonth = $aboveRow !== null && in_array($aboveRow, $monthToRow, true);

            if ($aboveIsDate && !$aboveIsMonth) {
                $pos = $thisRow;
                shiftRows($dateToRow, $monthToRow, $pos);
                $sheets->insertWeekRow($pos);
                elog("Week separator (backfill) → row $pos");
                $inserted++;
            }
        }
    }

    // ── Pass 2: Add EXACTLY ONE ROW ───────────────────────────────────────────
    $lastDateTs = findLastDateTs($dateToRow);

    if ($lastDateTs === null) {
        // No dates yet: start with next workday
        $nextTs = strtotime('today');
        while (isWeekend($nextTs)) $nextTs += 86400;
        $nextDateStr = date('d.m.Y', $nextTs);
        $nextDow = (int)date('w', $nextTs);

        $pos = findInsertRow($nextDateStr, array_merge($dateToRow, $monthToRow));
        if ($nextDow === 1) {
            // Next workday is Monday → check if separator needed
            $abovePos = $pos - 1;
            $aboveIsDate  = in_array($abovePos, $dateToRow, true);
            $aboveIsMonth = in_array($abovePos, $monthToRow, true);
            if ($aboveIsDate) {
                shiftRows($dateToRow, $monthToRow, $pos);
                $sheets->insertWeekRow($pos);
                elog("Week separator (first Monday) → row $pos");
            } else {
                shiftRows($dateToRow, $monthToRow, $pos);
                $sheets->insertDateRow($nextDateStr, $pos);
                $dateToRow[$nextDateStr] = $pos;
                elog("First date: $nextDateStr → row $pos");
            }
        } else {
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertDateRow($nextDateStr, $pos);
            $dateToRow[$nextDateStr] = $pos;
            elog("First date: $nextDateStr → row $pos");
        }
        $inserted++;
    } else {
        // Find next workday after last existing date
        $nextWorkTs = $lastDateTs + 86400;
        while (isWeekend($nextWorkTs)) $nextWorkTs += 86400;
        $nextWorkDateStr = date('d.m.Y', $nextWorkTs);
        $nextWorkDow = (int)date('w', $nextWorkTs);

        $pos = findInsertRow($nextWorkDateStr, array_merge($dateToRow, $monthToRow));
        if ($nextWorkDow === 1) {
            // Next workday is Monday
            $abovePos = $pos - 1;
            $aboveIsDate  = in_array($abovePos, $dateToRow, true);
            $aboveIsMonth = in_array($abovePos, $monthToRow, true);

            if ($aboveIsDate) {
                // Friday above → need separator
                shiftRows($dateToRow, $monthToRow, $pos);
                $sheets->insertWeekRow($pos);
                elog("Week separator (before Monday) → row $pos");
            } else {
                // Separator already exists or month above → add Monday date
                shiftRows($dateToRow, $monthToRow, $pos);
                $sheets->insertDateRow($nextWorkDateStr, $pos);
                $dateToRow[$nextWorkDateStr] = $pos;
                elog("Date (Monday): $nextWorkDateStr → row $pos");
            }
        } else {
            // Regular workday (Tue-Fri)
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertDateRow($nextWorkDateStr, $pos);
            $dateToRow[$nextWorkDateStr] = $pos;
            elog("Date: $nextWorkDateStr → row $pos");
        }
        $inserted++;
    }

    elog("Total inserted rows in this run: $inserted");
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
