<?php
// One-time: add Юрченков → column F to TECH_COLUMNS in env.php + clear resource cache.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

$envFile = dirname(__DIR__) . '/env.php';
if (!is_file($envFile)) { echo "ERROR: env.php not found\n"; exit(1); }

$content = file_get_contents($envFile);

$new = <<<'PHP'
define('TECH_COLUMNS', [
    'Тусюк'    => 'B',
    'Муха'     => 'D',
    'Козлянко' => 'E',
    'Юрченков' => 'F',
]);
PHP;

$updated = preg_replace(
    "/define\('TECH_COLUMNS'\s*,\s*\[.*?\]\s*\);/s",
    $new,
    $content,
    -1,
    $count
);

if ($count === 0) {
    echo "ERROR: TECH_COLUMNS not found in env.php\n";
    exit(1);
}

file_put_contents($envFile, $updated);
echo "env.php updated: Юрченков → F added\n";

// Clear resource names cache so next run re-fetches USERS mapping
require_once dirname(__DIR__) . '/api/store.php';
require_once $envFile;
if (defined('RESOURCE_NAMES_FILE') && is_file(RESOURCE_NAMES_FILE)) {
    unlink(RESOURCE_NAMES_FILE);
    echo "resource_names cache cleared\n";
} else {
    echo "resource_names cache: nothing to clear\n";
}
