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

    // ── 4. Fetch unchecked deals ──────────────────────────────────────────
    $deals = [];
    if (!empty($toCheck)) {
        $deals = fetchDealBatch(array_keys($toCheck));
        logline('Fetched deals: ' . count($deals));
    }

    // ── 5. Determine colors ───────────────────────────────────────────────
    $newGreen = [];
    $colorMap = [];

    foreach ($deals as $deal) {
        $dealId = (string)$deal['ID'];
        $color  = determineColor($deal, $categories);
        if ($color !== null) {
            $colorMap[$dealId] = $color;
            if ($color === 'green') {
                $newGreen[$dealId] = true;
            }
        }
    }

    foreach ($greenState as $dealId => $_) {
        $colorMap[$dealId] = 'green';
    }

    logline('Colors: green=' . count($newGreen) . ' persisted=' . count($greenState)
        . ', red=' . (count($colorMap) - count($newGreen) - count($greenState)));

    // ── 6. Persist new greens ─────────────────────────────────────────────
    if (!empty($newGreen)) {
        storeWrite($COLOR_STATE, array_merge($greenState, $newGreen));
    }

    // ── 7. Build batchUpdate ──────────────────────────────────────────────
    $sheets    = new GoogleSheets(SHEETS_ID);
    $colMap    = loadColumnMap($sheets);
    $dateToRow = $sheets->readColumnA()['dates'] ?? [];

    $updates = [];
    foreach ($state as $key => $deals) {
        $parts   = explode('|', $key, 2);
        $date    = $parts[0];
        $surname = $parts[1] ?? '';
        $col     = $colMap[$surname] ?? null;
        $row     = $dateToRow[$date] ?? null;
        if ($col === null || $row === null) continue;

        $text = '';
        $runs = [];
        $num  = 1;
        foreach ($deals as $dealId => $info) {
            $color = $colorMap[(string)$dealId] ?? null;
            if ($text !== '') $text .= "\n";
            $prefix = $num . '. ';
            $format = ['link' => ['uri' => $info['url']]];
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
 * Handles "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS", "DD.MM.YYYY", and array values.
 */
function dateFromUf(array $deal, string $ufField): string
{
    $val = $deal[$ufField] ?? null;
    if ($val === null || $val === '') return '';
    $raw = is_string($val) ? $val : (is_array($val) ? ($val['value'] ?? '') : '');
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) return $m[1];
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $raw, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    return '';
}

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
        // Check first element — if it's an array with ID, or a non-zero int
        $first = $val[0] ?? null;
        if ($first === null) return false;
        if (is_int($first) && $first > 0) return true;
        if (is_array($first) && !empty($first['id'] ?? $first['ID'] ?? '')) return true;
        if (is_string($first) && trim($first) !== '') return true;
    }
    return false;
}

/**
 * Determine link color for a deal.
 * Returns 'green', 'red', or null (no rule matched — leave default).
 */
function determineColor(array $deal, array $categories): ?string
{
    $today = date('Y-m-d');
    $catId = (int)($deal['CATEGORY_ID'] ?? -1);
    $cat   = $categories[$catId] ?? null;
    if ($cat === null) return null;

    $catName = mb_strtolower(trim($cat['name']));

    // Find matching rule set for this category
    $rules = null;
    foreach (colorRules() as $keyword => $ruleSet) {
        if (mb_strpos($catName, $keyword) !== false) {
            $rules = $ruleSet;
            break;
        }
    }
    if ($rules === null) return null;

    foreach ($rules as [$brigadeField, $actField]) {
        $date = dateFromUf($deal, $brigadeField);
        if ($date === '' || $date > $today) continue;
        // Date has arrived or passed — check the act file
        return actFilled($deal, $actField) ? 'green' : 'red';
    }

    return null;
}
