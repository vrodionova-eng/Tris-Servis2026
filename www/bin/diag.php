<?php
// Diagnostic: dump raw calendar event data for "empty" events.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// Events that return SECT_ID=0 — dump full raw response
foreach ([491, 493, 497] as $id) {
    echo "=== calendar.event.getbyid($id) raw ===\n";
    try {
        $r = b24wh('calendar.event.getbyid', ['id' => $id]);
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n\n";
    }
}
