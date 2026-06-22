<?php
// Diagnostic: show booking field data from first matching deal.
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// Show raw data of the most recently modified deal + all booking fields
$r = b24wh('crm.item.list', [
    'entityTypeId' => 2,
    'order'  => ['ID' => 'DESC'],
    'select' => array_merge(['ID', 'TITLE', 'DATE_MODIFY'], B24_BOOKING_FIELDS),
    'start'  => 0,
]);

$items = $r['items'] ?? [];
if (empty($items)) { echo "No deals found.\n"; exit(1); }

$deal = $items[0];
echo "Latest deal: #{$deal['id']} {$deal['title']}\n";
echo "Modified: {$deal['DATE_MODIFY']}\n\n";

foreach (B24_BOOKING_FIELDS as $f) {
    $v = $deal[$f] ?? null;
    echo "$f = " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
}
