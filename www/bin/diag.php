<?php
// Diagnostic: fetch booking records by IDs found in deal fields.
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Find a deal with booking IDs
$select = array_merge(['ID', 'TITLE', 'CATEGORY_ID'], B24_BOOKING_FIELDS);
$found  = null;
$start  = 0;

while ($found === null && $start < 2000) {
    $items = b24wh('crm.deal.list', [
        'order'  => ['ID' => 'DESC'],
        'select' => $select,
        'start'  => $start,
    ]);
    if (!is_array($items) || empty($items)) break;

    foreach ($items as $deal) {
        foreach (B24_BOOKING_FIELDS as $f) {
            $raw = $deal[$f] ?? null;
            if (is_array($raw) && !empty($raw)) {
                $found = $deal;
                break 2;
            }
        }
    }
    $start += 50;
}

if ($found === null) { echo "No deal with booking IDs found.\n"; exit(1); }

echo "Deal #{$found['ID']} {$found['TITLE']} (cat={$found['CATEGORY_ID']})\n\n";

// 2. Collect all booking IDs from this deal
$bookingIds = [];
foreach (B24_BOOKING_FIELDS as $f) {
    $raw = $found[$f] ?? null;
    if (is_array($raw) && !empty($raw)) {
        echo "$f = " . json_encode($raw) . "\n";
        foreach ($raw as $id) {
            $bookingIds[(int)$id] = $f;
        }
    }
}

echo "\n=== Booking records ===\n";

// 3. Try crm.resourcebooking.list filtered by these IDs
try {
    $r = b24wh('crm.resourcebooking.list', [
        'filter' => ['ID' => array_keys($bookingIds)],
        'select' => ['*'],
    ]);
    echo "crm.resourcebooking.list result:\n";
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "crm.resourcebooking.list ERROR: " . $e->getMessage() . "\n\n";

    // Fallback: try .get for each ID
    foreach (array_keys($bookingIds) as $id) {
        try {
            $r = b24wh('crm.resourcebooking.get', ['id' => $id]);
            echo "crm.resourcebooking.get($id):\n";
            echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        } catch (Throwable $e2) {
            echo "crm.resourcebooking.get($id) ERROR: " . $e2->getMessage() . "\n";
        }
    }
}
