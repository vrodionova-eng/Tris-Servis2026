<?php
// Diagnostic: show booking field data from first matching deal.
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// Try to find first deal with any booking field
$r = b24wh('crm.item.list', [
    'entityTypeId' => 2,
    'select' => array_merge(['ID', 'TITLE'], B24_BOOKING_FIELDS),
    'start' => 0,
]);

foreach ($r['items'] ?? [] as $deal) {
    foreach (B24_BOOKING_FIELDS as $f) {
        if (!empty($deal[$f])) {
            echo "Deal #{$deal['id']}: {$deal['title']}\n";
            echo "$f:\n";
            $val = is_string($deal[$f]) ? json_decode($deal[$f], true) : $deal[$f];
            echo json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            exit(0);
        }
    }
}
echo "No deals with booking fields found.\n";
