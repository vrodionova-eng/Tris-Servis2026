<?php
// Diagnostic: dump ALL fields of deal #887 to find date storage.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// Full deal #887
$deal = b24wh('crm.deal.get', ['id' => 887]);

echo "=== All non-empty UF fields of deal #887 ===\n";
foreach ($deal as $key => $val) {
    if (strpos($key, 'UF_') === false) continue;
    if ($val === null || $val === '' || $val === [] || $val === false) continue;
    echo "$key = " . json_encode($val, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== booking.v1.booking.list filter by deal ===\n";
// Try to find bookings linked to deal 887
foreach (['entityId', 'crmEntityId', 'entityTypeId', 'dealId'] as $filterKey) {
    try {
        $r = b24wh('booking.v1.booking.list', ['filter' => [$filterKey => 887]]);
        $items = $r['bookings'] ?? $r['items'] ?? (is_array($r) ? array_values($r)[0] ?? [] : []);
        if (!empty($items)) {
            echo "FOUND with filter[$filterKey=887]: " . json_encode($items, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Throwable $e) {
        // silent
    }
}
echo "Done.\n";
