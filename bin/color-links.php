<?php
// Cron: color links in Google Sheets based on deal document status.
// Schedule: */15 * * * * /usr/bin/php /var/www/Tris-Servis2026/bin/color-links.php
//
// Rules:
//   МАППИНГ: UF-поле даты бригады → UF-поле акта (тип Файл)
//
//   Воронка "Сервисное обслуживание":
//     UF_CRM_1750775559215  (Сервисная бригада)         → UF_CRM_1770287721239 (Акт подписанный)
//     UF_CRM_1751015039070  (Сервисная бригада запчасти) → UF_CRM_1760359069161 (Акт выставленный темп)
//
//   Воронка "Плановое обслуживание":
//     UF_CRM_1750920048783  (Сервисная бригада ТО-1)    → UF_CRM_1758266160075 (Акт ТО-1)
//     UF_CRM_1750920231839  (Сервисная бригада ТО-2)    → UF_CRM_1758530158437 (Акт ТО-2)
//
//   Если дата в поле бригады ≤ today:
//     - акт (файл) заполнен    → ссылка 🟢 зелёная
//     - акт (файл) пуст        → ссылка 🔴 красная
//   Если дата не наступила или поля нет — цвет не меняем.
//   Один раз подтверждённые зелёные сделки больше не проверяем.
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
$LOCK_FILE = DATA_ROOT . '/color-links.lock';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0700, true);
$LOG_FILE = $LOG_DIR . '/' . date('Y-m-d') . '-color.log';

$lock = @fopen($LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

// Coloring rewrites cell text from cron-cells.php as well as its colors.
// Hold the sync lock from BEFORE reading state until all writes finish.
// Non-blocking acquisition also avoids deadlocks with the deployment locks.
$syncLock = @fopen(DATA_ROOT . '/cron.lock', 'c');
if (!$syncLock || !flock($syncLock, LOCK_EX | LOCK_NB)) exit(0);

function logline(string $s): void
{
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, '[' . date('c') . '] ' . $s . "\n", FILE_APPEND);
}

if (!defined('B24_WEBHOOK_URL') || B24_WEBHOOK_URL === '') {
    logline('SKIP: B24_WEBHOOK_URL not set');
    exit(0);
}
if (!is_file(GOOGLE_SA_FILE)) {
    logline('SKIP: Google SA key not found');
    exit(0);
}

$started = microtime(true);
logline('=== color-links start ===');

try {
    runJob();
} catch (Throwable $e) {
    logline('EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

logline('=== color-links end (' . round(microtime(true) - $started, 2) . 's) ===');

// ─────────────────────────────────────────────────────────────────────────────

/**
 * RULES: [categoryKeyword => [[brigadeDateField, actField], ...]]
 * brigadeDateField — UF_CRM_* containing the brigade date
 * actField        — UF_CRM_* containing the file (act document)
 */
function colorRules(): array
{
    return [
        'сервисн' => [
            ['UF_CRM_1750775559215', 'UF_CRM_1770287721239'],
            ['UF_CRM_1751015039070', 'UF_CRM_1760359069161'],
        ],
        'планов' => [
            ['UF_CRM_1750920048783', 'UF_CRM_1758266160075'],
            ['UF_CRM_1750920231839', 'UF_CRM_1758530158437'],
        ],
    ];
}

function allUfFields(): array
{
    return [
        'UF_CRM_1750775559215',
        'UF_CRM_1751015039070',
        'UF_CRM_1750920048783',
        'UF_CRM_1750920231839',
        'UF_CRM_1770287721239',
        'UF_CRM_1760359069161',
        'UF_CRM_1758266160075',
        'UF_CRM_1758530158437',
    ];
}

function runJob(): void
{
    $COLOR_STATE = DATA_ROOT . '/deal-colors.php';
    $CELLS_STATE = DATA_ROOT . '/cron-cells.php';

    // ── 1. Read cell state ────────────────────────────────────────────────
    $state = storeRead($CELLS_STATE) ?? [];
    if (empty($state)) {
        logline('No cells state, nothing to color');
        return;
    }

    $allDealIds = [];
    foreach ($state as $key => $deals) {
        foreach ($deals as $dealId => $info) {
            $allDealIds[(string)$dealId] = true;
        }
    }
    logline('Unique deals in sheet: ' . count($allDealIds));

    // ── 2. Already-green deals ────────────────────────────────────────────
    $greenState = storeRead($COLOR_STATE) ?? [];
    $toCheck    = array_diff_key($allDealIds, $greenState);
    logline('Already green: ' . count($greenState) . ', to check: ' . count($toCheck));

    // ── 3. Category map ───────────────────────────────────────────────────
    $categories = fetchCategoryMap();
    logline('Categories: ' . json_encode(array_keys($categories), JSON_UNESCAPED_UNICODE));

    // ── 4. Build deal→allSheetDates from cron-cells state ────────────────
    //     Key: 'DD.MM.YYYY|Surname', value: [dealId => ...]
    //     A deal can appear on multiple dates (different brigade fields).
    $dealAllDates = [];
    foreach ($state as $key => $deals) {
        $d = explode('|', $key, 2)[0]; // DD.MM.YYYY
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) continue;
        $ymd = $m[3] . '-' . $m[2] . '-' . $m[1]; // YYYY-MM-DD
        foreach ($deals as $dealId => $info) {
            $dealAllDates[(string)$dealId][] = $ymd;
        }
    }

    // ── 4b. Booking start datetimes (written by process.php) ─────────────
    //     dealId => latest booking timestamp. Used so coloring only happens
    //     once BOTH the date AND the time of the booking have arrived.
    $bookingTimes = storeRead(DATA_ROOT . '/deal-booking-times.php') ?? [];

    // ── 5. Fetch unchecked deals ──────────────────────────────────────────
    $deals = [];
    if (!empty($toCheck)) {
        $deals = fetchDealBatch(array_keys($toCheck));
        logline('Fetched deals: ' . count($deals));
    }

    // ── 6. Build per-deal data: category rules + act-filled status ────────────
    //     Color is decided PER CELL (by that cell's date), so here we only
    //     precompute for each deal: which rule set applies, and whether the
    //     act file is filled. The date/time gate is applied per cell in step 9.
    $dealInfo  = []; // dealId => ['anyChecked'=>bool, 'allGreen'=>bool]
    $newGreen  = [];

    foreach ($deals as $deal) {
        $dealId = (string)$deal['ID'];
        $info   = analyzeDeal($deal, $categories);
        if ($info === null) {
            $dates = $dealAllDates[$dealId] ?? [];
            $dbg = [
                'id' => $dealId,
                'cat_id' => $deal['CATEGORY_ID'] ?? '?',
                'all_dates' => $dates,
            ];
            foreach (allUfFields() as $uf) {
                $dbg[$uf] = json_encode($deal[$uf] ?? null, JSON_UNESCAPED_UNICODE);
            }
            logline('NO-RULE: ' . json_encode($dbg, JSON_UNESCAPED_UNICODE));
            continue;
        }
        $dealInfo[$dealId] = $info;
    }

    logline('Deals with rules: ' . count($dealInfo) . ', persisted green=' . count($greenState));

    // ── 9. Build batchUpdate (color decided per cell by that cell's date) ────
    $sheets    = new GoogleSheets(SHEETS_ID);
    $colMap    = loadColumnMap($sheets);
    $dateToRow = $sheets->readColumnA()['dates'] ?? [];

    $today = date('Y-m-d');
    $now   = time();

    $updates = [];
    foreach ($state as $key => $deals) {
        $parts   = explode('|', $key, 2);
        $date    = $parts[0];                 // DD.MM.YYYY — this cell's date
        $surname = $parts[1] ?? '';
        $col     = $colMap[$surname] ?? null;
        $row     = $dateToRow[$date] ?? null;
        if ($col === null || $row === null) continue;

        $cellYmd = dmyToYmdC($date);          // YYYY-MM-DD for this cell

        $text = '';
        $runs = [];
        $num  = 1;
        foreach ($deals as $dealId => $info) {
            $dealId = (string)$dealId;
            if ($text !== '') $text .= "\n";
            $prefix = $num . '. ';
            $format = ['link' => ['uri' => $info['url']]];

            $color = colorForCell($dealId, $cellYmd, $dealInfo, $greenState, $bookingTimes, $today, $now);
            if ($color === 'green') {
                $format['foregroundColor'] = ['red' => 0.0, 'green' => 0.5, 'blue' => 0.0];
            } elseif ($color === 'red') {
                $format['foregroundColor'] = ['red' => 0.7, 'green' => 0.0, 'blue' => 0.0];
            }
            $runs[] = [
                'startIndex' => mb_strlen($text, 'UTF-8'),
                'format'     => $format,
            ];
            $text .= $prefix . $info['title'];
            $num++;
        }
        $updates[] = ['cellRef' => $col . $row, 'text' => $text, 'runs' => $runs];
    }

    // Persist greens that turned green on an ARRIVED date this run
    foreach ($state as $key => $deals) {
        $date    = explode('|', $key, 2)[0];
        $cellYmd = dmyToYmdC($date);
        foreach ($deals as $dealId => $_) {
            $dealId = (string)$dealId;
            $c = colorForCell($dealId, $cellYmd, $dealInfo, $greenState, $bookingTimes, $today, $now);
            if ($c === 'green') $newGreen[$dealId] = true;
        }
    }
    if (!empty($newGreen)) {
        storeWrite($COLOR_STATE, array_merge($greenState, $newGreen));
        logline('Persisted new greens: ' . count($newGreen));
    }

    if (!empty($updates)) {
        $sheets->batchUpdate($updates);
        logline('Cells updated: ' . count($updates));
    } else {
        logline('No cell updates');
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function fetchCategoryMap(): array
{
    try {
        $items = b24wh('crm.category.list', ['entityTypeId' => 2]);
    } catch (Throwable $e) {
        logline('fetchCategoryMap error: ' . $e->getMessage());
        return [];
    }
    $cats = is_array($items) ? ($items['categories'] ?? $items) : [];
    $map  = [];
    foreach ((array)$cats as $cat) {
        $id   = (int)($cat['id'] ?? $cat['ID'] ?? 0);
        $name = trim((string)($cat['name'] ?? $cat['NAME'] ?? ''));
        if ($id > 0 && $name !== '') {
            $map[$id] = ['id' => $id, 'name' => $name];
        }
    }
    return $map;
}

function fetchDealBatch(array $dealIds): array
{
    if (empty($dealIds)) return [];
    $select = array_merge(['ID', 'TITLE', 'CATEGORY_ID'], allUfFields());

    $all    = [];
    $chunks = array_chunk(array_unique($dealIds), 50);
    foreach ($chunks as $chunk) {
        try {
            $items = b24wh('crm.deal.list', [
                'filter' => ['ID' => $chunk],
                'select' => $select,
            ]);
            if (is_array($items)) $all = array_merge($all, $items);
        } catch (Throwable $e) {
            logline('fetchDealBatch error: ' . $e->getMessage());
        }
    }
    return $all;
}

/**
 * Extract a clean YYYY-MM-DD date from a UF field value.
 * Brigade UF fields store booking IDs (array of ints) — resolved via $bookingDates.
 * Handles "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS", "DD.MM.YYYY", and array values.
 */
/**
 * Check if a deal's act file field is actually "filled" (has a file uploaded).
 * B24 UF file fields can be:
 *   - empty string / null → empty
 *   - integer (file ID)  → filled
 *   - array [fileId, ...] → filled
 *   - string "1","2" etc  → filled
 */
function actFilled(array $deal, string $actField): bool
{
    $val = $deal[$actField] ?? null;
    if ($val === null) return false;
    if (is_int($val) && $val > 0) return true;
    if (is_string($val) && trim($val) !== '' && trim($val) !== '0') return true;
    if (is_array($val) && !empty($val)) {
        // Single file object: {"id":6137,"showUrl":"...","downloadUrl":"..."}
        if (isset($val['id']) && (int)$val['id'] > 0) return true;
        // Array of files: [{"id":6141,...}, ...]
        $first = $val[0] ?? null;
        if ($first !== null) {
            if (is_int($first) && $first > 0) return true;
            if (is_array($first) && !empty($first['id'] ?? $first['ID'] ?? '')) return true;
            if (is_string($first) && trim($first) !== '') return true;
        }
    }
    return false;
}

/**
 * Analyze a deal: which rule set applies, and is the act filled.
 * Returns ['allGreen'=>bool] or null when no rule matches / no brigade field set.
 * Date/time gating is NOT done here — it's per-cell in colorForCell().
 */
function analyzeDeal(array $deal, array $categories): ?array
{
    $catId = (int)($deal['CATEGORY_ID'] ?? -1);
    $cat   = $categories[$catId] ?? null;
    if ($cat === null) return null;

    $catName = mb_strtolower(trim($cat['name']));

    $rules = null;
    foreach (colorRules() as $keyword => $ruleSet) {
        if (mb_strpos($catName, $keyword) !== false) {
            $rules = $ruleSet;
            break;
        }
    }
    if ($rules === null) return null;

    $anyChecked = false;
    $allGreen   = true;
    foreach ($rules as [$brigadeField, $actField]) {
        $val = $deal[$brigadeField] ?? [];
        if (!is_array($val) || empty($val)) continue; // no booking → skip this pair
        $anyChecked = true;
        if (!actFilled($deal, $actField)) $allGreen = false;
    }

    if (!$anyChecked) return null;
    return ['allGreen' => $allGreen];
}

/**
 * Decide the link color for a deal in a SPECIFIC cell (by that cell's date).
 * Returns 'green', 'red', or null (leave default/blue).
 *
 * - Persisted green deals stay green everywhere.
 * - Otherwise: color only when the booking date+time for THIS cell's date
 *   has arrived. Not arrived → null (blue).
 */
function colorForCell(string $dealId, string $cellYmd, array $dealInfo, array $greenState, array $bookingTimes, string $today, int $now): ?string
{
    if (isset($greenState[$dealId])) return 'green';

    $info = $dealInfo[$dealId] ?? null;
    if ($info === null) return null;

    // Booking start time recorded for this exact date?
    $ts = $bookingTimes[$dealId][$cellYmd] ?? null;
    if (is_int($ts) && $ts > 0) {
        if ($now < $ts) return null; // this cell's booking hasn't started yet
    } else {
        // No timestamp for this date → fall back to date-only comparison
        if ($cellYmd === '' || $cellYmd > $today) return null;
    }

    return $info['allGreen'] ? 'green' : 'red';
}

/** Convert 'DD.MM.YYYY' → 'YYYY-MM-DD'. Returns '' on failure. */
function dmyToYmdC(string $d): string
{
    $p = explode('.', $d);
    return count($p) === 3 ? $p[2] . '-' . $p[1] . '-' . $p[0] : '';
}
