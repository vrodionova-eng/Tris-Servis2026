<?php
// Cron worker: sync B24 bookings → Google Sheets.
// Schedule: */2 * * * * /usr/bin/php /var/www/Tris-Servis2026/bin/process.php
//
// Strategy: employee-calendar-centric.
//   1. Read personal calendars of all techs (calendar.event.get).
//   2. Filter events by EVENT_TYPE="#resourcebooking#".
//   3. Resolve UF_CRM_CAL_EVENT (D_<id>) and read the current deal title.
//   4. Write richText (clickable) cells to Google Sheets.
//
// State: DATA_ROOT/cron-cells.php stores previous cell set for stale-cell clearing.
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
require_once __DIR__ . '/../api/b24.php';
require_once __DIR__ . '/../api/sheets.php';
require_once __DIR__ . '/../api/sync.php';

$LOG_DIR   = DATA_ROOT . '/cron-logs';
$LOCK_FILE = DATA_ROOT . '/cron.lock';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0700, true);
$LOG_FILE = $LOG_DIR . '/' . date('Y-m-d') . '.log';

function logline(string $s): void
{
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, '[' . date('c') . '] ' . $s . "\n", FILE_APPEND);
}

$lock = @fopen($LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

if (!defined('B24_WEBHOOK_URL') || B24_WEBHOOK_URL === '') {
    logline('SKIP: B24_WEBHOOK_URL not set');
    exit(0);
}
if (!is_file(GOOGLE_SA_FILE)) {
    logline('SKIP: Google SA key not found (' . GOOGLE_SA_FILE . ')');
    exit(0);
}

$started = microtime(true);
logline('=== start ===');

try {
    runJob();
} catch (Throwable $e) {
    logline('EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

logline('=== end (' . round(microtime(true) - $started, 2) . 's) ===');

// ─────────────────────────────────────────────────────────────────────────────

function runJob(): void
{
    // State file: stores set of cells written in the previous run (for stale clearing)
    $CELLS_STATE = DATA_ROOT . '/cron-cells.php';

    // ── 1. Spreadsheet structure ──────────────────────────────────────────────
    $sheets      = new GoogleSheets(SHEETS_ID);
    $columnData  = $sheets->readColumnA();
    $dateToRow   = $columnData['dates'];   // 'DD.MM.YYYY' => rowNum
    $monthToRow  = $columnData['months'];  // 'YYYY-MM'    => rowNum
    logline('Sheet rows: ' . count($dateToRow) . ', month headers: ' . count($monthToRow));

    $columnMap = loadColumnMap($sheets);
    logline('Columns: ' . json_encode($columnMap, JSON_UNESCAPED_UNICODE));
    if (empty($columnMap)) {
        logline('ERROR: no columns in spreadsheet row 1, abort');
        return;
    }

    // ── 2. Tech users ─────────────────────────────────────────────────────────
    $techUsers = loadTechUsers($columnMap); // [userId => surname]
    logline('Tech users: ' . json_encode($techUsers, JSON_UNESCAPED_UNICODE));
    if (empty($techUsers)) {
        logline('WARNING: no tech users matched column headers');
        return;
    }

    // ── 3. Calendar bookings ──────────────────────────────────────────────────
    // Window: start of current month → end of month +2
    $syncFrom = date('Y-m-01');
    $syncTo   = date('Y-m-t', strtotime('+2 months'));
    logline("Sync window: $syncFrom → $syncTo");

    $bookings = fetchTechBookings($techUsers, $syncFrom, $syncTo);
    logline('Bookings from calendar: ' . count($bookings));

    // ── 4. Read linked deals by stable ID, once per deal ────────────────────
    $portal   = (string)parse_url(B24_WEBHOOK_URL, PHP_URL_HOST);
    $dealMap = [];
    foreach ($bookings as $booking) {
        $id = $booking['dealId'];
        if (array_key_exists($id, $dealMap)) continue;
        $deal = b24wh('crm.deal.get', ['id' => $id]);
        if (!is_array($deal) || (string)($deal['ID'] ?? '') !== $id
            || !isset($deal['TITLE'], $deal['STAGE_SEMANTIC_ID'])) {
            throw new RuntimeException("Invalid response for linked deal $id; sync aborted");
        }
        // Preserve the existing active-stage policy, independently of titles.
        $dealMap[$id] = $deal['STAGE_SEMANTIC_ID'] === 'P'
            ? ['id' => $id, 'url' => "https://{$portal}/crm/deal/details/{$id}/", 'title' => (string)$deal['TITLE']]
            : null;
    }
    logline('Linked deals read: ' . count($dealMap));

    // ── 5. Build new assignments ──────────────────────────────────────────────
    // Key: 'DD.MM.YYYY|Surname', value: [dealId => ['id', 'url', 'title']]
    $newAssign = [];
    $bookingTimes = []; // dealId => ['YYYY-MM-DD' => booking start timestamp, ...]

    foreach ($bookings as $b) {
        $deal = $dealMap[$b['dealId']] ?? null;
        if ($deal === null) {
            continue;
        }
        $dealEntry = $deal;
        logline('Booking event ' . $b['eventId'] . ' -> deal ' . $deal['id'] . ' on ' . $b['date'] . ' / ' . $b['surname']);

        // Collect booking start datetime per date for this deal (time-based coloring
        // per cell). A deal may appear on several dates; each cell colors only when
        // ITS OWN booking date+time has arrived.
        $bTs = bookingTs($b['dateTimeFrom'] ?? '');
        if ($bTs > 0) {
            $did = (string)$deal['id'];
            foreach (expandDates($b['date'], $b['dateTo']) as $date) {
                $ymd = dmyToYmd($date);
                if ($ymd !== '') {
                    // keep the earliest start time recorded for that date
                    if (!isset($bookingTimes[$did][$ymd]) || $bTs < $bookingTimes[$did][$ymd]) {
                        $bookingTimes[$did][$ymd] = $bTs;
                    }
                }
            }
        }

        foreach (expandDates($b['date'], $b['dateTo']) as $date) {
            $key = $date . '|' . $b['surname'];
            $newAssign[$key][$deal['id']] = $dealEntry;
        }
    }

    if (in_array('--dry-run', $GLOBALS['argv'] ?? [], true)) {
        $previous = storeRead($CELLS_STATE) ?? [];
        $changed = 0;
        $cleared = 0;
        foreach (array_unique(array_merge(array_keys($previous), array_keys($newAssign))) as $key) {
            $before = $previous[$key] ?? [];
            $after = $newAssign[$key] ?? [];
            if ($before == $after) continue;
            if ($after === []) $cleared++;
            else $changed++;
            logline('DRY RUN ' . ($after === [] ? 'CLEAR ' : 'WRITE ') . $key
                . ': deal IDs [' . implode(',', array_keys($before)) . '] -> [' . implode(',', array_keys($after)) . ']');
        }
        logline('DRY RUN: assignments=' . count($newAssign) . ', write=' . $changed . ', clear=' . $cleared
            . '; no sheet or sync-state writes');
        return;
    }

    // Persist booking start times (read by color-links.php — no extra API calls)
    storeWrite(DATA_ROOT . '/deal-booking-times.php', $bookingTimes);

    logline('Assignments: ' . count($newAssign));

    // Link coloring is owned by color-links.php (per-cell by booking date+time).
    // process.php only writes links; it no longer computes or applies colors.

    // ── 7. Stale cell clearing ────────────────────────────────────────────────
    $oldAssign = storeRead($CELLS_STATE) ?? []; // previously written keys
    $toRemove  = array_diff_key($oldAssign, $newAssign);

    // ── 8. Insert missing date rows ───────────────────────────────────────────
    $allDates = [];
    foreach (array_keys($newAssign) as $key) {
        $allDates[explode('|', $key, 2)[0]] = true;
    }
    ksort($allDates); // process in order so row-shift is correct

    foreach (array_keys($allDates) as $date) {
        $monthKey   = dateToMonthKey($date);
        $minDatePos = null; // date must go at or after this row

        // Insert month separator before the first date of a new month
        if ($monthKey !== '' && !isset($monthToRow[$monthKey])) {
            $mPos = findInsertRow($date, array_merge($dateToRow, $monthToRow));
            foreach ($dateToRow as &$r) { if ($r >= $mPos) $r++; }
            foreach ($monthToRow as &$r) { if ($r >= $mPos) $r++; }
            unset($r);
            $sheets->insertMonthRow(ruMonthLabel($date), $mPos);
            $monthToRow[$monthKey] = $mPos;
            $minDatePos = $mPos + 1; // date must go AFTER the month header
            logline("Month row inserted: " . ruMonthLabel($date) . " → $mPos");
        }

        if (!isset($dateToRow[$date])) {
            $pos = max($minDatePos ?? 1, findInsertRow($date, $dateToRow));
            foreach ($dateToRow as &$r) { if ($r >= $pos) $r++; }
            foreach ($monthToRow as &$r) { if ($r >= $pos) $r++; }
            unset($r);
            $sheets->insertDateRow($date, $pos);
            $dateToRow[$date] = $pos;
            logline("Row inserted: $date → $pos");
        }
    }

    // ── 9. Build Sheets batchUpdate ───────────────────────────────────────────
    $updates = [];

    // Clear stale cells
    foreach (array_keys($toRemove) as $key) {
        [$date, $surname] = explode('|', $key, 2);
        $col = $columnMap[$surname] ?? null;
        $row = $dateToRow[$date]   ?? null;
        if ($col !== null && $row !== null) {
            $updates[] = ['cellRef' => $col . $row, 'text' => '', 'runs' => []];
        }
    }

    // Write only NEW or CHANGED cells. Rewriting an unchanged cell would send
    // richTextValue runs without foregroundColor → Sheets resets text to default
    // link blue, wiping color-links.php's colors within 2 minutes.
    $written = 0;
    foreach ($newAssign as $key => $deals) {
        if (isset($oldAssign[$key]) && $oldAssign[$key] == $deals) continue;
        [$date, $surname] = explode('|', $key, 2);
        $col = $columnMap[$surname] ?? null;
        $row = $dateToRow[$date]   ?? null;
        if ($col === null || $row === null) continue;

        $text = '';
        $runs = [];
        $num  = 1;
        foreach ($deals as $d) {
            if ($text !== '') $text .= "\n";
            $prefix = $num . '. ';
            $format = ['link' => ['uri' => $d['url']]];
            $runs[] = [
                'startIndex' => mb_strlen($text, 'UTF-8'),
                'format'     => $format,
            ];
            $text .= $prefix . $d['title'];
            $num++;
        }
        $updates[] = ['cellRef' => $col . $row, 'text' => $text, 'runs' => $runs];
        $written++;
    }

    $skipped = count($newAssign) - $written;
    if (!empty($updates)) {
        $sheets->batchUpdate($updates);
        logline('Cells updated: ' . count($updates) . ' (clear=' . count($toRemove) . ', write=' . $written . ', unchanged=' . $skipped . ')');
    } else {
        logline('No cell changes');
    }

    // ── 10. Save state ────────────────────────────────────────────────────────
    storeWrite($CELLS_STATE, $newAssign);
    storeWrite(LAST_SYNC_FILE, ['ts' => time()]);
    logline('State saved');
}

/** Parse "DD.MM.YYYY HH:MM:SS" → Unix timestamp. Returns 0 on failure. */
function bookingTs(string $dateTime): int
{
    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?/', $dateTime, $m)) {
        return 0;
    }
    return mktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0), (int)$m[2], (int)$m[1], (int)$m[3]);
}

/** Convert 'DD.MM.YYYY' → 'YYYY-MM-DD'. Returns '' on failure. */
function dmyToYmd(string $d): string
{
    $p = explode('.', $d);
    return count($p) === 3 ? $p[2] . '-' . $p[1] . '-' . $p[0] : '';
}
