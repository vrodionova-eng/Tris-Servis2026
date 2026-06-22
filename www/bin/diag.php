<?php
// Diagnostic: inspect deal #887 booking fields and calendar events in detail.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

$dealId = 887;

echo "=== Deal #$dealId — booking field values per field ===\n";
$deals = b24wh('crm.deal.list', [
    'filter' => ['ID' => $dealId],
    'select' => array_merge(['ID', 'TITLE'], B24_BOOKING_FIELDS),
]);
$deal = $deals[0] ?? null;
if (!$deal) { echo "Deal not found\n"; exit(1); }
echo "Title: {$deal['TITLE']}\n\n";

foreach (B24_BOOKING_FIELDS as $fn) {
    $raw = $deal[$fn] ?? null;
    $ids = is_array($raw) ? array_filter(array_map('intval', $raw)) : [];
    echo "[$fn] → " . (empty($ids) ? '(empty)' : implode(', ', $ids)) . "\n";
    foreach ($ids as $eid) {
        try {
            $ev = b24wh('calendar.event.getbyid', ['id' => $eid]);
            if (empty($ev) || !isset($ev['ID'])) {
                echo "  event $eid → null/deleted\n";
            } else {
                printf("  event %-5s SECT_ID=%-4s OWNER_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                    $eid,
                    $ev['SECT_ID']   ?? '?',
                    $ev['OWNER_ID']  ?? '?',
                    $ev['DATE_FROM'] ?? '?',
                    $ev['NAME']      ?? ''
                );
            }
        } catch (Throwable $e) {
            echo "  event $eid → ERROR: {$e->getMessage()}\n";
        }
    }
}
