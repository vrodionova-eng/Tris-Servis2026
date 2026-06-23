<?php
// One-time: import Google SA JSON key → data/google-sa-key.php (store format).
// Usage: php /var/www/Tris-Servis2026/bin/import-sa-key.php /tmp/sa-key.json
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$src = $argv[1] ?? '/tmp/sa-key.json';
if (!is_file($src)) { echo "ERROR: file not found: $src\n"; exit(1); }

$json = file_get_contents($src);
$data = json_decode($json, true);
if (!$data || !isset($data['private_key'], $data['client_email'])) {
    echo "ERROR: invalid SA JSON (missing private_key or client_email)\n"; exit(1);
}

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';

storeWrite(GOOGLE_SA_FILE, $data);
echo "OK: SA key saved to " . GOOGLE_SA_FILE . "\n";
echo "client_email: " . $data['client_email'] . "\n";
