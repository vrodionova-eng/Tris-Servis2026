<?php
// CRON-воркер. Single-tenant: один портал = одна задача по расписанию.
//
// Пример cron-строки (раз в 15 минут):
//   */15 * * * * /usr/bin/php /<path>/www/bin/process.php >/dev/null 2>&1
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
require_once __DIR__ . '/../api/b24.php';
require_once __DIR__ . '/../api/lib.php';

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

if (!is_file(B24_TOKENS_FILE)) {
    logline('SKIP: приложение ещё не установлено (нет b24-tokens.php)');
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

/**
 * Заглушка cron-задачи. Заменить телом конкретного проекта.
 *
 * Пример:
 *   $b24 = b24();
 *   $deals = $b24->call('crm.item.list', ['entityTypeId' => 2, 'filter' => [...]]);
 *   // ... обработать
 *   logline('processed ' . count($deals['result']['items'] ?? []) . ' deals');
 */
function runJob(): void {
    logline('NOOP: runJob() — заглушка, заменить телом задачи проекта');
}
