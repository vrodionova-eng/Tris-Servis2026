<?php
// Diagnostic: check if 495/497 are booking.v1 resource IDs + find bookings for them.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Try booking.v1.resource.get for 495, 497
echo "=== booking.v1.resource.get ===\n";
foreach ([495, 497] as $id) {
    try {
        $r = b24wh('booking.v1.resource.get', ['id' => $id]);
        echo "resource $id: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "resource $id ERROR: " . $e->getMessage() . "\n";
    }
}

// 2. booking.v1.booking.list filtered by resourceIds
echo "\n=== booking.v1.booking.list filter resourceIds=[495,497] ===\n";
try {
    $r = b24wh('booking.v1.booking.list', ['filter' => ['resourceId' => [495, 497]]]);
    $items = $r['bookings'] ?? $r['items'] ?? (is_array($r) ? array_values($r)[0] ?? [] : []);
    echo "Count: " . count((array)$items) . "\n";
    foreach ((array)$items as $b) {
        echo "  id=" . ($b['id']??'?')
            . " from=" . ($b['datePeriod']['from']['timestamp']??'?')
            . " to=" . ($b['datePeriod']['to']['timestamp']??'?')
            . " resources=" . json_encode($b['resourceIds']??[]) . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 3. All bookings sorted by most recent — look for June 2026 timestamps
echo "\n=== booking.v1.booking.list (all) — compact ===\n";
try {
    $r = b24wh('booking.v1.booking.list', []);
    $items = $r['bookings'] ?? $r['items'] ?? (is_array($r) ? array_values($r)[0] ?? [] : []);
    foreach ((array)$items as $b) {
        $from = $b['datePeriod']['from']['timestamp'] ?? 0;
        echo "  id=" . ($b['id']??'?')
            . " from=$from (" . date('d.m.Y H:i', $from) . ")"
            . " resources=" . json_encode($b['resourceIds']??[]) . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
