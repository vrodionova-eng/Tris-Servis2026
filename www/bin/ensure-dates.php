<?php
// Cron worker: extend the date grid one workday at a time.
// Schedule: 0 0 * * * /usr/bin/php /var/www/Tris-Servis2026/bin/ensure-dates.php
//
// Logic:
//   1. Backfill missing MONTH separators for existing dates.
//   2. Add the next workday after the last existing date (skips Sat/Sun).
//      If that workday is a Monday, insert the grey week separator AND the
//      Monday date in the SAME run (so a weekend never leaves a dangling
//      separator without its Monday, and never two separators in a row).
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

    // ── Pass 1: Backfill month separators (week separators handled in Pass 2) ─
    $sortedDates = array_keys($dateToRow);
    usort($sortedDates, fn($a, $b) => dateToTs($a) <=> dateToTs($b));

    foreach ($sortedDates as $dateStr) {
        $ts       = dateToTs($dateStr);
        $monthNum = (int)date('n', $ts);
        $yearNum  = (int)date('Y', $ts);
        $monthKey = sprintf('%04d-%02d', $yearNum, $monthNum);

        if (!isset($monthToRow[$monthKey])) {
            $pos = findInsertRow($dateStr, array_merge($dateToRow, $monthToRow));
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertMonthRow(ruMonthLabel($dateStr), $pos);
            $monthToRow[$monthKey] = $pos;
            elog("Month separator: " . ruMonthLabel($dateStr) . " → row $pos");
            $inserted++;
        }
    }

    // ── Pass 2: Add next workday (with its week separator if Monday) ──────────
    $lastDateTs = findLastDateTs($dateToRow);

    // Next workday after the last existing date (or today if sheet is empty)
    $baseTs     = $lastDateTs ?? strtotime('today');
    $nextWorkTs = $lastDateTs === null ? $baseTs : $baseTs + 86400;
    while (isWeekend($nextWorkTs)) $nextWorkTs += 86400;
    $nextWorkDateStr = date('d.m.Y', $nextWorkTs);
    $nextWorkDow     = (int)date('w', $nextWorkTs);

    if (isset($dateToRow[$nextWorkDateStr])) {
        elog("Date $nextWorkDateStr already exists, nothing to add");
        elog("Total inserted rows in this run: $inserted");
        return;
    }

    $pos = findInsertRow($nextWorkDateStr, array_merge($dateToRow, $monthToRow));

    // If next workday is Monday and the row above the insert point holds a date
    // (i.e. previous Friday), insert the grey week separator first, then the
    // Monday date right after it — both in this single run.
    if ($nextWorkDow === 1) {
        $abovePos    = $pos - 1;
        $aboveIsDate = in_array($abovePos, $dateToRow, true);
        if ($aboveIsDate) {
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertWeekRow($pos);
            elog("Week separator (before Monday) → row $pos");
            $inserted++;
            $pos++; // date goes right below the separator
        }
    }

    shiftRows($dateToRow, $monthToRow, $pos);
    $sheets->insertDateRow($nextWorkDateStr, $pos);
    $dateToRow[$nextWorkDateStr] = $pos;
    elog("Date: $nextWorkDateStr → row $pos");
    $inserted++;

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
