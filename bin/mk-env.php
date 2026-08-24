<?php
// One-time: create env.php on the server. CLI only.
// Usage: php /var/www/Tris-Servis2026/bin/mk-env.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$envFile = dirname(__DIR__) . '/env.php';

if (is_file($envFile)) {
    echo "env.php already exists at $envFile\n";
    echo "Delete it first if you want to recreate: rm $envFile\n";
    exit(0);
}

echo "Enter B24_WEBHOOK_URL (from Б24 → Разработчикам → Входящий вебхук): ";
$webhook = trim(fgets(STDIN));
if (strpos($webhook, 'http') !== 0) {
    echo "ERROR: invalid URL\n"; exit(1);
}

$root = dirname(__DIR__);
$data = $root . '/data';

$content = <<<PHP
<?php
declare(strict_types=1);
define('B24_WEBHOOK_URL', '$webhook');
define('DATA_ROOT', '$data');
define('APP_URL',   '');
define('APP_PATH',  '$root');
define('B24_TOKENS_FILE',     DATA_ROOT . '/b24-tokens.php');
define('SETTINGS_FILE',       DATA_ROOT . '/settings.php');
define('SESSIONS_FILE',       DATA_ROOT . '/sessions.php');
define('STATE_FILE',          DATA_ROOT . '/state.php');
define('LAST_SYNC_FILE',      DATA_ROOT . '/last_sync.php');
define('DEAL_CELLS_FILE',     DATA_ROOT . '/deal_cells.php');
define('DEAL_INFO_FILE',      DATA_ROOT . '/deal_info.php');
define('RESOURCE_NAMES_FILE', DATA_ROOT . '/resource_names.php');
define('SHEETS_ID',       '1jjBjuwvxChxmk0L5_Rk9Sr4it9v47AZCCbaHXEyMhr4');
define('SHEETS_SHEET',    'Лист1');
define('GOOGLE_SA_FILE',  DATA_ROOT . '/google-sa-key.php');
define('GOOGLE_TOK_FILE', DATA_ROOT . '/google_token.php');
define('B24_BOOKING_FIELDS', []);
define('TECH_COLUMNS', []);
PHP;

@mkdir($data, 0700, true);
file_put_contents($envFile, $content);
echo "env.php created at $envFile\n";
echo "data/ dir: $data\n";
echo "\nDon't forget to restore google-sa-key.php in data/\n";
