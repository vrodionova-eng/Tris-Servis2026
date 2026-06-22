<?php
// Diagnostic: confirm calendar event structure + all sections from all booking fields.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Calendar events for deal #887 booking field values [495, 497]
echo "=== calendar.event.getbyid ===\n";
foreach ([495, 497] as $id) {
    try {
        $r = b24wh('calendar.event.getbyid', ['id' => $id]);
        echo "Event $id: DATE_FROM=" . ($r['DATE_FROM'] ?? '?')
            . " DATE_TO=" . ($r['DATE_TO'] ?? '?')
            . " SECT_ID=" . ($r['SECT_ID'] ?? '?')
            . " NAME=" . ($r['NAME'] ?? '?') . "\n";
    } catch (Throwable $e) {
        echo "Event $id ERROR: " . $e->getMessage() . "\n";
    }
}

// 2. All sections from ALL booking fields
echo "\n=== Sections from all booking fields ===\n";
$fields = b24wh('crm.deal.fields', []);
foreach (B24_BOOKING_FIELDS as $fieldName) {
    $field = $fields[$fieldName] ?? null;
    if (!$field) { echo "$fieldName: not found\n"; continue; }
    $sections = $field['settings']['RESOURCES']['resource']['SECTIONS'] ?? [];
    echo "$fieldName:\n";
    foreach ($sections as $s) {
        echo "  id=" . ($s['ID']??'?') . " name=" . ($s['NAME']??'?') . "\n";
    }
}
