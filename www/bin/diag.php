<?php
// Diagnostic: explore booking.v1 module - find the service that contains
// Муха/Юрченков resources, then get June 2026 bookings for deal #887.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. List all booking services
echo "=== booking.v1.service.list ===\n";
try {
    $r = b24wh('booking.v1.service.list', []);
    $services = $r['services'] ?? $r ?? [];
    if (empty($services)) {
        echo "  (empty or different key)\n";
        echo "  raw: " . json_encode($r) . "\n";
    } else {
        foreach ((array)$services as $s) {
            printf("  id=%-4s name=%s\n", $s['id'] ?? $s['ID'] ?? '?', $s['name'] ?? $s['NAME'] ?? '?');
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 2. List all resources (all services) - look for Муха, Юрченков etc.
echo "\n=== booking.v1.resource.list (all) ===\n";
try {
    $r = b24wh('booking.v1.resource.list', []);
    $resources = $r['resources'] ?? $r ?? [];
    if (empty($resources)) {
        echo "  (empty)\n";
        echo "  raw: " . json_encode($r) . "\n";
    } else {
        foreach ((array)$resources as $res) {
            printf("  id=%-4s name=%s\n",
                $res['id'] ?? $res['ID'] ?? '?',
                $res['name'] ?? $res['NAME'] ?? json_encode($res)
            );
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 3. booking.v1.booking.list for deal #887 — show ALL with timestamps decoded
echo "\n=== booking.v1.booking.list (entityTypeId=2, entityId=887) ===\n";
try {
    $r = b24wh('booking.v1.booking.list', [
        'entityTypeId' => 2,
        'entityId'     => 887,
    ]);
    $bookings = $r['bookings'] ?? $r ?? [];
    if (empty($bookings)) {
        echo "  (empty)\n";
        echo "  raw: " . json_encode($r) . "\n";
    } else {
        foreach ((array)$bookings as $b) {
            $from = isset($b['from']) ? date('d.m.Y H:i', (int)$b['from']) : '?';
            $to   = isset($b['to'])   ? date('d.m.Y H:i', (int)$b['to'])   : '?';
            printf("  id=%-6s from=%s to=%s serviceId=%s resourceIds=%s\n",
                $b['id']          ?? '?',
                $from, $to,
                $b['serviceId']   ?? '?',
                json_encode($b['resourceIds'] ?? [])
            );
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 4. booking.v1.booking.list without entity filter — get recent bookings
echo "\n=== booking.v1.booking.list (no filter, recent) ===\n";
try {
    $r = b24wh('booking.v1.booking.list', []);
    $bookings = $r['bookings'] ?? $r ?? [];
    if (empty($bookings)) {
        echo "  (empty)\n";
        echo "  raw keys: " . implode(', ', array_keys((array)$r)) . "\n";
    } else {
        foreach ((array)$bookings as $b) {
            $from = isset($b['from']) ? date('d.m.Y H:i', (int)$b['from']) : '?';
            $to   = isset($b['to'])   ? date('d.m.Y H:i', (int)$b['to'])   : '?';
            printf("  id=%-6s from=%s to=%s svcId=%s rids=%s entityTypeId=%s entityId=%s\n",
                $b['id']           ?? '?',
                $from, $to,
                $b['serviceId']    ?? '?',
                json_encode($b['resourceIds'] ?? []),
                $b['entityTypeId'] ?? '?',
                $b['entityId']     ?? '?'
            );
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 5. Try booking.v1.booking.getbyid for IDs from previous session (that returned 2025 data)
echo "\n=== booking.v1 method discovery ===\n";
foreach (['booking.v1.booking.get', 'booking.v1.booking.getList', 'booking.v1.booking.listByEntity'] as $method) {
    try {
        $r = b24wh($method, ['entityTypeId' => 2, 'entityId' => 887]);
        echo "  $method → " . json_encode($r) . "\n";
    } catch (Throwable $e) {
        echo "  $method → ERROR: " . $e->getMessage() . "\n";
    }
}
