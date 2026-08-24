<?php
// Cron worker: append exactly one new workday date per run.
// Schedule: 0 0 * * * /usr/bin/php /var/www/Tris-Servis2026/bin/ensure-dates.php
//
// Every run ALWAYS appends one new date (the next workday after the last
// existing date, skipping Sat/Sun). If that date falls in a new month, its
// month header is inserted just above it; if it's a Monday with a date right
// above, the grey week separator is inserted just above it. Separators are
// companions to the date — never substitutes — so a day never passes without
// a new date being added.
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

    // ── Backfill: month separators for dates already in the sheet ────────────
    // (These are months that appeared earlier without the new-date path adding
    // their header. New months introduced by THIS run are handled below.)
    $sortedDates = array_keys($dateToRow);
    usort($sortedDates, fn($a, $b) => dateToTs($a) <=> dateToTs($b));

    foreach ($sortedDates as $dateStr) {
        $ts       = dateToTs($dateStr);
        $monthKey = sprintf('%04d-%02d', (int)date('Y', $ts), (int)date('n', $ts));

        if (!isset($monthToRow[$monthKey])) {
            $pos = findInsertRow($dateStr, array_merge($dateToRow, $monthToRow));
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertMonthRow(ruMonthLabel($dateStr), $pos);
            $monthToRow[$monthKey] = $pos;
            elog("Month separator (backfill): " . ruMonthLabel($dateStr) . " → row $pos");
            $inserted++;
        }
    }

    // ── ALWAYS add exactly one new workday date ───────────────────────────────
    // Accompanying separators (new-month header, grey week separator before a
    // Monday) are inserted together WITH that date, in the same run — they are
    // companions to the date, not substitutes for it. So a day never passes
    // without a new date being appended.
    $lastDateTs = findLastDateTs($dateToRow);

    $nextWorkTs = $lastDateTs === null ? strtotime('today') : $lastDateTs + 86400;
    while (isWeekend($nextWorkTs)) $nextWorkTs += 86400;
    $nextWorkDateStr = date('d.m.Y', $nextWorkTs);
    $nextWorkDow     = (int)date('w', $nextWorkTs);
    $nextMonthKey    = date('Y-m', $nextWorkTs);

    if (isset($dateToRow[$nextWorkDateStr])) {
        elog("Date $nextWorkDateStr already exists, nothing to add");
        elog("Total inserted rows in this run: $inserted");
        return;
    }

    $pos = findInsertRow($nextWorkDateStr, array_merge($dateToRow, $monthToRow));

    // 1) New-month header for the date we're about to add
    if (!isset($monthToRow[$nextMonthKey])) {
        shiftRows($dateToRow, $monthToRow, $pos);
        $sheets->insertMonthRow(ruMonthLabel($nextWorkDateStr), $pos);
        $monthToRow[$nextMonthKey] = $pos;
        elog("Month separator: " . ruMonthLabel($nextWorkDateStr) . " → row $pos");
        $inserted++;
        $pos++; // date goes below the month header
    }

    // 2) Grey week separator if this date is a Monday and a date sits directly above
    if ($nextWorkDow === 1) {
        $aboveIsDate = in_array($pos - 1, $dateToRow, true);
        if ($aboveIsDate) {
            shiftRows($dateToRow, $monthToRow, $pos);
            $sheets->insertWeekRow($pos);
            elog("Week separator (before Monday) → row $pos");
            $inserted++;
            $pos++; // date goes below the week separator
        }
    }

    // 3) The date itself — always
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
