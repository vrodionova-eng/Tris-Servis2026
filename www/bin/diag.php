<?php
// Diagnostic: get exact booking records + resource names.
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Get specific booking records by ID (from deal #887)
$bookingIds = [495, 497];
echo "=== booking.v1.booking.get for IDs: " . implode(', ', $bookingIds) . " ===\n";
foreach ($bookingIds as $id) {
    try {
        $r = b24wh('booking.v1.booking.get', ['id' => $id]);
        echo "ID $id: " . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    } catch (Throwable $e) {
        echo "ID $id ERROR: " . $e->getMessage() . "\n\n";
    }
}

// 2. List resources
echo "=== booking.v1.resource.list ===\n";
try {
    $r = b24wh('booking.v1.resource.list', []);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
