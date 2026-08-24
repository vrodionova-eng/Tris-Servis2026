<?php
// One-time CLI script: appends sync constants to env.php.
// Usage: php /var/www/Tris-Servis2026/bin/patch-env.php <webhook_url>
// Example: php bin/patch-env.php https://tris.bitrix24.by/rest/77/TOKEN/
//
// Safe to run twice — checks for duplicate before writing.

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

$webhookUrl = trim($argv[1] ?? '');
if ($webhookUrl === '') {
    echo "Usage: php patch-env.php <B24_WEBHOOK_URL>\n";
    exit(1);
}

$envFile = dirname(__DIR__) . '/env.php';
if (!is_file($envFile)) {
    echo "ERROR: env.php not found at $envFile\n";
    exit(1);
}

if (strpos((string)file_get_contents($envFile), 'B24_WEBHOOK_URL') !== false) {
    echo "Already patched (B24_WEBHOOK_URL found). Nothing to do.\n";
    exit(0);
}

$addition = <<<PHP

// ── Webhook B24 ──────────────────────────────────────────────────────────
define('B24_WEBHOOK_URL', '$webhookUrl');

// ── Google Sheets ─────────────────────────────────────────────────────────
define('SHEETS_ID',       '1jjBjuwvxChxmk0L5_Rk9Sr4it9v47AZCCbaHXEyMhr4');
define('SHEETS_SHEET',    'Лист1');
define('GOOGLE_SA_FILE',  DATA_ROOT . '/google-sa-key.php');
define('GOOGLE_TOK_FILE', DATA_ROOT . '/google_token.php');

// ── Поля бронирования B24 ─────────────────────────────────────────────────
define('B24_BOOKING_FIELDS', [
    'UF_CRM_1750775559215',
    'UF_CRM_1751015039070',
    'UF_CRM_1750920048783',
    'UF_CRM_1750920231839',
]);

// ── Карта техник → колонка ────────────────────────────────────────────────
define('TECH_COLUMNS', [
    'Тусюк'    => 'B',
    'Кузовко'  => 'C',
    'Муха'     => 'D',
    'Козлянко' => 'E',
    'Юрченков' => 'F',
]);

// ── State-файлы синхронизации ─────────────────────────────────────────────
define('LAST_SYNC_FILE',      DATA_ROOT . '/last_sync.php');
define('DEAL_CELLS_FILE',     DATA_ROOT . '/deal_cells.php');
define('DEAL_INFO_FILE',      DATA_ROOT . '/deal_info.php');
define('RESOURCE_NAMES_FILE', DATA_ROOT . '/resource_names.php');
PHP;

file_put_contents($envFile, $addition, FILE_APPEND);
echo "env.php patched OK\n";
