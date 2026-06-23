<?php
// Diagnostic: use calendar.resource.booking.list — the correct API for
// "Бронирование ресурсов" UF field type. Not calendar.event.*, not booking.v1.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. calendar.resource.list — get all resources (employees + cars)
echo "=== calendar.resource.list ===\n";
try {
    $res = b24wh('calendar.resource.list', []);
    $list = $res['resources'] ?? $res ?? [];
    if (empty($list)) {
        echo "  (empty)\n";
        echo "  raw: " . json_encode($res) . "\n";
    } else {
        foreach ((array)$list as $r) {
            printf("  id=%-4s name=%s\n",
                $r['ID'] ?? $r['id'] ?? '?',
                $r['NAME'] ?? $r['name'] ?? json_encode($r)
            );
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 2. calendar.resource.booking.list for June 2026
echo "\n=== calendar.resource.booking.list (June 2026) ===\n";
try {
    $res = b24wh('calendar.resource.booking.list', [
        'filter' => [
            'DATE_FROM' => '2026-06-01',
            'DATE_TO'   => '2026-06-30',
        ],
    ]);
    $bookings = $res['bookings'] ?? $res ?? [];
    if (empty($bookings)) {
        echo "  (empty)\n";
        echo "  raw: " . json_encode($res) . "\n";
    } else {
        foreach ((array)$bookings as $b) {
            printf("  id=%-6s RESOURCE_ID=%-4s SECTION_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                $b['ID']          ?? $b['id']          ?? '?',
                $b['RESOURCE_ID'] ?? $b['resource_id'] ?? '?',
                $b['SECTION_ID']  ?? $b['section_id']  ?? '?',
                $b['DATE_FROM']   ?? $b['date_from']   ?? '?',
                mb_substr((string)($b['NAME'] ?? $b['name'] ?? ''), 0, 60)
            );
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 3. calendar.resource.booking.list without filter — see what comes back
echo "\n=== calendar.resource.booking.list (no filter) ===\n";
try {
    $res = b24wh('calendar.resource.booking.list', []);
    $bookings = $res['bookings'] ?? $res ?? [];
    echo "  total: " . count((array)$bookings) . "\n";
    foreach (array_slice((array)$bookings, 0, 5) as $b) {
        echo "  " . json_encode($b) . "\n";
    }
    if (empty($bookings)) {
        echo "  raw keys: " . implode(', ', array_keys((array)$res)) . "\n";
        echo "  raw: " . json_encode($res) . "\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 4. Try with deal #887 filter
echo "\n=== calendar.resource.booking.list (deal #887) ===\n";
try {
    $res = b24wh('calendar.resource.booking.list', [
        'filter' => [
            'ENTITY_TYPE' => 'deal',
            'ENTITY_ID'   => 887,
        ],
    ]);
    $bookings = $res['bookings'] ?? $res ?? [];
    if (empty($bookings)) {
        echo "  (empty)\n";
        echo "  raw: " . json_encode($res) . "\n";
    } else {
        foreach ((array)$bookings as $b) {
            echo "  " . json_encode($b) . "\n";
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
