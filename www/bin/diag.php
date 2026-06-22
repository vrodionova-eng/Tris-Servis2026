<?php
// Diagnostic: booking records + resource names.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Booking records for IDs from deal #887
echo "=== booking.v1.booking.get ===\n";
foreach ([495, 497] as $id) {
    try {
        $r = b24wh('booking.v1.booking.get', ['id' => $id]);
        echo "ID=$id: from=" . ($r['datePeriod']['from']['timestamp'] ?? '?')
            . " to=" . ($r['datePeriod']['to']['timestamp'] ?? '?')
            . " resourceIds=" . json_encode($r['resourceIds'] ?? []) . "\n";
    } catch (Throwable $e) {
        echo "ID=$id ERROR: " . $e->getMessage() . "\n";
    }
}

// 2. All resources (all types)
echo "\n=== booking.v1.resource.list (all) ===\n";
try {
    $r = b24wh('booking.v1.resource.list', []);
    $list = $r['resources'] ?? (is_array($r) ? $r : []);
    foreach ($list as $res) {
        echo "  id={$res['id']} typeId={$res['typeId']} name={$res['name']}\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
