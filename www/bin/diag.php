<?php
// Diagnostic: booking records for IDs from deal #887.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

echo "=== booking.v1.booking.get for IDs 495, 497 ===\n";
foreach ([495, 497] as $id) {
    try {
        $r = b24wh('booking.v1.booking.get', ['id' => $id]);
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    } catch (Throwable $e) {
        echo "ID $id ERROR: " . $e->getMessage() . "\n";
    }
}

echo "=== booking.v1.resource.list (compact) ===\n";
try {
    $r = b24wh('booking.v1.resource.list', []);
    // Try different response shapes
    $list = $r['resources'] ?? $r['resource'] ?? (array_values($r)[0] ?? []);
    if (!is_array($list)) $list = [];
    foreach ($list as $res) {
        if (!is_array($res)) continue;
        echo "  id=" . ($res['id'] ?? '?')
            . " typeId=" . ($res['typeId'] ?? '?')
            . " name=" . ($res['name'] ?? '?') . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
