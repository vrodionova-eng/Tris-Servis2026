<?php
// Diagnostic: show booking field USERS mapping + raw calendar events for SECT_ID=0 events.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Dump SECTIONS and USERS from booking field settings
echo "=== crm.deal.fields: booking field SECTIONS + USERS ===\n";
$fields = b24wh('crm.deal.fields', []);
foreach (B24_BOOKING_FIELDS as $fn) {
    $f = $fields[$fn] ?? null;
    echo "Field: $fn\n";
    if (!$f) { echo "  NOT FOUND\n\n"; continue; }
    $resource = $f['settings']['RESOURCES']['resource'] ?? [];

    echo "  SECTIONS (calendar resource sections):\n";
    foreach ($resource['SECTIONS'] ?? [] as $s) {
        printf("    ID=%-4s  NAME=%s\n", $s['ID'] ?? '?', $s['NAME'] ?? '?');
    }
    echo "  USERS (employees → personal calendar):\n";
    foreach ($resource['USERS'] ?? [] as $u) {
        printf("    ID=%-4s  NAME=%s\n", $u['ID'] ?? '?', $u['NAME'] ?? '?');
    }
    echo "\n";
}

// 2. Full raw calendar events for events that showed SECT_ID=0
echo "=== calendar.event.getbyid for SECT_ID=0 events ===\n";
foreach ([491, 493, 497] as $id) {
    echo "--- event $id ---\n";
    try {
        $r = b24wh('calendar.event.getbyid', ['id' => $id]);
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
