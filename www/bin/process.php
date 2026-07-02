<?php
// Cron worker: sync B24 bookings → Google Sheets.
// Schedule: */2 * * * * /usr/bin/php /var/www/Tris-Servis2026/bin/process.php
//
// Strategy: employee-calendar-centric.
//   1. Read personal calendars of all techs (calendar.event.get).
//   2. Filter events by EVENT_TYPE="#resourcebooking#".
//   3. Strip "Бронирование: " from NAME → match deal by title.
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

    // ── 4. Active deals (for title→URL mapping) ───────────────────────────────
    $portal   = (string)parse_url(B24_WEBHOOK_URL, PHP_URL_HOST);
    $titleMap = []; // title => ['id' => ..., 'url' => ...]
    $start    = 0;
    do {
        $items = b24wh('crm.deal.list', [
            'filter' => ['STAGE_SEMANTIC_ID' => 'P'],  // active stages only
            'select' => ['ID', 'TITLE'],
            'order'  => ['ID' => 'DESC'],
            'start'  => $start,
        ]);
        if (!is_array($items)) $items = [];
        foreach ($items as $deal) {
            $t = trim((string)($deal['TITLE'] ?? ''));
            if ($t !== '' && !isset($titleMap[$t])) {
                $id = (string)$deal['ID'];
                $titleMap[$t] = ['id' => $id, 'url' => "https://{$portal}/crm/deal/details/{$id}/"];
            }
        }
        $start += 50;
    } while (count($items) === 50 && $start < 2000);
    logline('Active deals: ' . count($titleMap));

    // ── 5. Build new assignments ──────────────────────────────────────────────
    // Key: 'DD.MM.YYYY|Surname', value: [dealId => ['id', 'url', 'title']]
    $newAssign = [];
    $missed    = [];

    foreach ($bookings as $b) {
        $deal = $titleMap[$b['title']] ?? null;
        if ($deal === null) {
            if ($b['title'] !== '') $missed[$b['title']] = true;
            continue;
        }
        $dealEntry = ['id' => $deal['id'], 'url' => $deal['url'], 'title' => $b['title']];

        foreach (expandDates($b['date'], $b['dateTo']) as $date) {
            $key = $date . '|' . $b['surname'];
            $newAssign[$key][$deal['id']] = $dealEntry;
        }
    }

    if (!empty($missed)) {
        logline('Titles not matched to active deals: ' . implode('; ', array_keys($missed)));
    }
    logline('Assignments: ' . count($newAssign));

    // ── 6. Determine link colors (every run, so colors are fresh) ──────────────
    $linkColors = determineLinkColors($newAssign);
    storeWrite(DATA_ROOT . '/link-colors.php', $linkColors);

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

    // Read link colors if available
    $linkColors = storeRead(DATA_ROOT . '/link-colors.php') ?? [];

    // Write new/updated cells
    foreach ($newAssign as $key => $deals) {
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
            $color  = $linkColors[(string)$d['id']] ?? null;
            if ($color === 'green') {
                $format['foregroundColor'] = ['red' => 0.0, 'green' => 0.5, 'blue' => 0.0];
            } elseif ($color === 'red') {
                $format['foregroundColor'] = ['red' => 0.7, 'green' => 0.0, 'blue' => 0.0];
            }
            $runs[] = [
                'startIndex' => mb_strlen($text, 'UTF-8'),
                'format'     => $format,
            ];
            $text .= $prefix . $d['title'];
            $num++;
        }
        $updates[] = ['cellRef' => $col . $row, 'text' => $text, 'runs' => $runs];
    }

    if (!empty($updates)) {
        $sheets->batchUpdate($updates);
        logline('Cells updated: ' . count($updates) . ' (clear=' . count($toRemove) . ', write=' . count($newAssign) . ')');
    } else {
        logline('No cell changes');
    }

    // ── 10. Save state ────────────────────────────────────────────────────────
    storeWrite($CELLS_STATE, $newAssign);
    storeWrite(LAST_SYNC_FILE, ['ts' => time()]);
    logline('State saved');
}

// ═══════════════════════════════════════════════════════════════════════════════
// Link color helpers — runs every cycle so colors are always fresh.
// ═══════════════════════════════════════════════════════════════════════════════

function determineLinkColors(array $newAssign): array
{
    $allDealIds = [];
    foreach ($newAssign as $deals) {
        foreach ($deals as $d) {
            $allDealIds[(string)$d['id']] = true;
        }
    }
    if (empty($allDealIds)) return [];

    $greenState = storeRead(DATA_ROOT . '/deal-colors.php') ?? [];
    $toCheck    = array_diff_key($allDealIds, $greenState);
    logline('Link colors: green=' . count($greenState) . ', to-check=' . count($toCheck));

    // Already-green deals → green
    $colors = [];
    foreach ($greenState as $id => $_) {
        $colors[$id] = 'green';
    }

    if (empty($toCheck)) return $colors;

    // Fetch categories and deals with UF fields
    $categories = [];
    try {
        $cats = b24wh('crm.category.list', ['entityTypeId' => 2]);
        foreach ((array)((is_array($cats) ? ($cats['categories'] ?? $cats) : [])) as $c) {
            $id   = (int)($c['id'] ?? $c['ID'] ?? 0);
            $name = trim((string)($c['name'] ?? $c['NAME'] ?? ''));
            if ($id > 0 && $name !== '') $categories[$id] = ['id' => $id, 'name' => $name];
        }
    } catch (Throwable $e) {
        logline('Link colors: category fetch error — ' . $e->getMessage());
    }

    $ufFields = [
        'UF_CRM_1750775559215', 'UF_CRM_1751015039070',
        'UF_CRM_1750920048783', 'UF_CRM_1750920231839',
        'UF_CRM_1770287721239', 'UF_CRM_1760359069161',
        'UF_CRM_1758266160075', 'UF_CRM_1758530158437',
    ];

    // Build deal→dates from newAssign
    $dealAllDates = [];
    foreach ($newAssign as $key => $deals) {
        $d = explode('|', $key, 2)[0];
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) continue;
        $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];
        foreach ($deals as $di) {
            $dealAllDates[(string)$di['id']][] = $ymd;
        }
    }

    // Fetch deals
    $select = array_merge(['ID', 'TITLE', 'CATEGORY_ID'], $ufFields);
    $deals  = [];
    foreach (array_chunk(array_keys($toCheck), 50) as $chunk) {
        try {
            $items = b24wh('crm.deal.list', ['filter' => ['ID' => $chunk], 'select' => $select]);
            if (is_array($items)) $deals = array_merge($deals, $items);
        } catch (Throwable $e) {
            logline('Link colors: deal fetch error — ' . $e->getMessage());
        }
    }

    $today = date('Y-m-d');
    $rules = [
        'сервисн' => [
            ['UF_CRM_1750775559215', 'UF_CRM_1770287721239'],
            ['UF_CRM_1751015039070', 'UF_CRM_1760359069161'],
        ],
        'планов' => [
            ['UF_CRM_1750920048783', 'UF_CRM_1758266160075'],
            ['UF_CRM_1750920231839', 'UF_CRM_1758530158437'],
        ],
    ];

    foreach ($deals as $deal) {
        $dealId = (string)$deal['ID'];
        $color  = null;

        // Latest date for this deal
        $dates = $dealAllDates[$dealId] ?? [];
        $dealDate = '';
        foreach ($dates as $dt) { if ($dt > $dealDate) $dealDate = $dt; }

        if ($dealDate !== '' && $dealDate <= $today) {
            $catId   = (int)($deal['CATEGORY_ID'] ?? -1);
            $cat     = $categories[$catId] ?? null;
            if ($cat !== null) {
                $catName = mb_strtolower(trim($cat['name']));
                $catRules = null;
                foreach ($rules as $kw => $rs) {
                    if (mb_strpos($catName, $kw) !== false) { $catRules = $rs; break; }
                }
                if ($catRules !== null) {
                    $anyChecked = false;
                    $allGreen   = true;
                    foreach ($catRules as [$brigadeField, $actField]) {
                        $val = $deal[$brigadeField] ?? [];
                        if (!is_array($val) || empty($val)) continue;
                        $anyChecked = true;
                        if (!actFilledP($deal, $actField)) $allGreen = false;
                    }
                    if ($anyChecked) {
                        $color = $allGreen ? 'green' : 'red';
                    }
                }
            }
        }

        if ($color === 'green') {
            $colors[$dealId] = 'green';
            $greenState[$dealId] = true;
        } elseif ($color === 'red') {
            $colors[$dealId] = 'red';
        }
    }

    // Persist greens
    storeWrite(DATA_ROOT . '/deal-colors.php', $greenState);
    logline('Link colors: total=' . count($colors));

    return $colors;
}

function actFilledP(array $deal, string $actField): bool
{
    $val = $deal[$actField] ?? null;
    if ($val === null) return false;
    if (is_int($val) && $val > 0) return true;
    if (is_string($val) && trim($val) !== '' && trim($val) !== '0') return true;
    if (is_array($val) && !empty($val)) {
        if (isset($val['id']) && (int)$val['id'] > 0) return true;
        $first = $val[0] ?? null;
        if ($first !== null) {
            if (is_int($first) && $first > 0) return true;
            if (is_array($first) && !empty($first['id'] ?? $first['ID'] ?? '')) return true;
            if (is_string($first) && trim($first) !== '') return true;
        }
    }
    return false;
}
