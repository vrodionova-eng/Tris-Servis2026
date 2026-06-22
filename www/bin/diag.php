<?php
// Diagnostic: show booking field USERS mapping + raw calendar events for SECT_ID=0 events.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Dump full raw settings for first booking field (to find where employees are stored)
echo "=== crm.deal.fields: full settings for first booking field ===\n";
$fields = b24wh('crm.deal.fields', []);
$firstField = B24_BOOKING_FIELDS[0] ?? null;
if ($firstField && isset($fields[$firstField])) {
    echo json_encode($fields[$firstField]['settings'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
} else {
    echo "Field not found\n\n";
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
