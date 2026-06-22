<?php
// CRON-воркер. Single-tenant: один портал = одна задача по расписанию.
//
// Пример cron-строки (раз в 2 минуты):
//   */2 * * * * /usr/bin/php /var/www/Tris-Servis2026/bin/process.php
//
// Защита: flock на DATA_ROOT/cron.lock — параллельные запуски не накладываются.
// Логи: DATA_ROOT/cron-logs/<date>.log с ротацией по дням.
//
// Куда дописывать бизнес-логику: блок «runJob()» ниже. Внутри использовать
// $b24 = b24() для REST-вызовов и helpers из api/lib.php для прочего.
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

function logline(string $s): void {
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, '[' . date('c') . '] ' . $s . "\n", FILE_APPEND);
}

// Анти-нахлёст: если предыдущий запуск ещё крутится — тихо выходим.
$lock = @fopen($LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

if (!defined('B24_WEBHOOK_URL') || B24_WEBHOOK_URL === '') {
    logline('SKIP: B24_WEBHOOK_URL не задан в env.php');
    exit(0);
}
if (!is_file(GOOGLE_SA_FILE)) {
    logline('SKIP: Google SA key не найден (' . GOOGLE_SA_FILE . ')');
    exit(0);
}

$started = microtime(true);
logline('=== start ===');

try {
    runJob();
} catch (Throwable $e) {
    logline('EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$duration = round(microtime(true) - $started, 2);
logline("=== end ({$duration}s) ===");

function runJob(): void
{
    // 1. Load state
    $lastSyncData = storeRead(LAST_SYNC_FILE);
    $lastSyncTs   = isset($lastSyncData['ts']) ? (int)$lastSyncData['ts'] : 0;
    $dealCells    = storeRead(DEAL_CELLS_FILE) ?? [];
    $dealInfo     = storeRead(DEAL_INFO_FILE)  ?? [];

    // 2. Google Sheets: read existing dates once
    $sheets    = new GoogleSheets(SHEETS_ID);
    $dateToRow = $sheets->readColumnA();
    logline('Sheet date rows: ' . count($dateToRow));

    // 3. Resource names (cached)
    $resourceNames = loadResourceNames();
    logline('Resources: ' . count($resourceNames));

    // 4. Fetch modified deals from B24 (paginated).
    // crm.deal.list returns UF_ fields when explicitly selected; crm.item.list does not.
    $portal = (string)parse_url(B24_WEBHOOK_URL, PHP_URL_HOST);
    $filter = $lastSyncTs > 0
        ? ['>=DATE_MODIFY' => date('Y-m-d\TH:i:s', $lastSyncTs)]
        : [];
    $select = array_merge(['ID', 'TITLE'], B24_BOOKING_FIELDS);

    $allDeals = [];
    $start    = 0;
    do {
        $items = b24wh('crm.deal.list', [
            'filter' => $filter,
            'select' => $select,
            'order'  => ['ID' => 'ASC'],
            'start'  => $start,
        ]);
        if (!is_array($items)) $items = [];
        $allDeals = array_merge($allDeals, $items);
        $start += 50;
    } while (count($items) === 50 && $start < 1000);

    logline('Deals fetched: ' . count($allDeals));

    if (empty($allDeals)) {
        storeWrite(LAST_SYNC_FILE, ['ts' => time()]);
        logline('No changes');
        return;
    }

    // 5. Process each deal, collect affected cells
    $affectedKeys = [];

    foreach ($allDeals as $deal) {
        $dealId = (string)($deal['ID'] ?? '');
        if ($dealId === '') continue;

        $title = (string)($deal['TITLE'] ?? '#' . $dealId);
        $url   = 'https://' . $portal . '/crm/deal/details/' . $dealId . '/';

        $oldCells = $dealCells[$dealId] ?? [];
        $newCells = parseBookings($deal, $resourceNames);

        foreach (array_merge($oldCells, $newCells) as $pos) {
            $affectedKeys[$pos['date'] . '|' . $pos['tech']] = true;
        }

        if (empty($newCells)) {
            unset($dealCells[$dealId], $dealInfo[$dealId]);
        } else {
            $dealCells[$dealId] = $newCells;
            $dealInfo[$dealId]  = ['title' => $title, 'url' => $url];
        }
    }

    // 6. For each affected cell: ensure date row exists, build richText update
    $updates = [];

    foreach (array_keys($affectedKeys) as $key) {
        [$date, $tech] = explode('|', $key, 2);
        $col = TECH_COLUMNS[$tech] ?? null;
        if ($col === null) continue;

        if (!isset($dateToRow[$date])) {
            $insertPos = findInsertRow($date, $dateToRow);
            // Shift rows >= insertPos down by 1
            foreach ($dateToRow as $d => &$r) {
                if ($r >= $insertPos) $r++;
            }
            unset($r);
            $sheets->insertDateRow($date, $insertPos);
            $dateToRow[$date] = $insertPos;
            logline("Row inserted: $date → $insertPos");
        }

        $row  = $dateToRow[$date];
        $rich = buildRichText($date, $tech, $dealCells, $dealInfo);
        $updates[] = [
            'cellRef' => $col . $row,
            'text'    => $rich !== null ? $rich['text'] : '',
            'runs'    => $rich !== null ? $rich['runs'] : [],
        ];
    }

    // 7. One batchUpdate call for all changes
    if (!empty($updates)) {
        $sheets->batchUpdate($updates);
        logline('Cells updated: ' . count($updates));
    }

    // 8. Persist state
    storeWrite(LAST_SYNC_FILE, ['ts' => time()]);
    storeWrite(DEAL_CELLS_FILE, $dealCells);
    storeWrite(DEAL_INFO_FILE,  $dealInfo);
    logline('State saved');
}
