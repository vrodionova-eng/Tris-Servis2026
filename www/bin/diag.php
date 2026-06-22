<?php
// Diagnostic: check if booking field IDs are calendar events.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

echo "=== calendar.event.getbyid for 495, 497 ===\n";
foreach ([495, 497] as $id) {
    try {
        $r = b24wh('calendar.event.getbyid', ['id' => $id]);
        echo "ID $id: " . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    } catch (Throwable $e) {
        echo "ID $id ERROR: " . $e->getMessage() . "\n\n";
    }
}

echo "=== crm.deal.fields (booking field type) ===\n";
try {
    $fields = b24wh('crm.deal.fields', []);
    $f = $fields['UF_CRM_1751015039070'] ?? null;
    if ($f) {
        echo json_encode($f, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "Field not found in deal.fields\n";
        // Show all UF fields
        foreach ($fields as $k => $v) {
            if (strpos($k, 'UF_') === 0) {
                echo "$k type=" . ($v['type'] ?? '?') . "\n";
            }
        }
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
