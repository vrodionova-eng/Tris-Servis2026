<?php
// One-time: add 5th booking field UF_CRM_1752501717195 to B24_BOOKING_FIELDS in env.php.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

$envFile = dirname(__DIR__) . '/env.php';
if (!is_file($envFile)) { echo "ERROR: env.php not found\n"; exit(1); }

$content = file_get_contents($envFile);

// Find existing B24_BOOKING_FIELDS definition and add 5th field
$new = "'UF_CRM_1752501717195',\n]";
$updated = preg_replace(
    "/(\])\s*\);\s*\/\/\s*B24_BOOKING_FIELDS|(\])\s*\);\s*\n(?=\s*define\('TECH_COLUMNS)/",
    "'UF_CRM_1752501717195',\n]);\n",
    $content,
    -1,
    $count
);

// More targeted replacement
if ($count === 0) {
    // Try to find the B24_BOOKING_FIELDS definition and add the field
    if (strpos($content, 'UF_CRM_1752501717195') !== false) {
        echo "Field UF_CRM_1752501717195 already present in env.php\n";
        exit(0);
    }

    // Find the closing ]) of B24_BOOKING_FIELDS
    $pattern = "/(define\('B24_BOOKING_FIELDS',\s*\[(?:[^]]*?))(])/s";
    $updated = preg_replace($pattern, "$1    'UF_CRM_1752501717195',\n$2", $content, -1, $count);
}

if ($count === 0) {
    echo "ERROR: could not find B24_BOOKING_FIELDS pattern\n";
    echo "Manual fix: add 'UF_CRM_1752501717195' to the B24_BOOKING_FIELDS array in env.php\n";
    // Show current definition
    if (preg_match("/define\('B24_BOOKING_FIELDS'.*?\);/s", $content, $m)) {
        echo "Current:\n" . $m[0] . "\n";
    }
    exit(1);
}

file_put_contents($envFile, $updated);
echo "env.php updated: added UF_CRM_1752501717195 to B24_BOOKING_FIELDS\n";

// Verify
if (strpos(file_get_contents($envFile), 'UF_CRM_1752501717195') !== false) {
    echo "Verification OK\n";
} else {
    echo "WARNING: verification failed, check env.php manually\n";
}

// Clear resource names cache
require_once dirname(__DIR__) . '/api/store.php';
require_once $envFile;
if (defined('RESOURCE_NAMES_FILE') && is_file(RESOURCE_NAMES_FILE)) {
    unlink(RESOURCE_NAMES_FILE);
    echo "resource_names cache cleared\n";
}
