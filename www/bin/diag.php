<?php
// Diagnostic: probe booking API namespaces to find working methods.
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// Known booking IDs from deal #887
$bookingIds = [495, 497];

$methods = [
    'booking.v1.booking.get'       => ['id' => $bookingIds[0]],
    'booking.v1.booking.list'      => ['filter' => ['ID' => $bookingIds]],
    'booking.booking.get'          => ['id' => $bookingIds[0]],
    'booking.booking.list'         => ['filter' => ['ID' => $bookingIds]],
    'crm.booking.get'              => ['id' => $bookingIds[0]],
    'userfieldconfig.getTypes'     => [],
];

foreach ($methods as $method => $params) {
    echo "--- $method ---\n";
    try {
        $r = b24wh($method, $params);
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n\n";
    }
}

// Also try listing available methods in booking scope
echo "--- scope.booking methods (via methods list) ---\n";
try {
    $r = b24wh('methods', ['scope' => 'booking']);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
