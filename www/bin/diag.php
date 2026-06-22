<?php
// Diagnostic: try booking.v1 API + crm.deal.get for fresh UF values.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

$dealId = 887;

// 1. Fresh UF field values via crm.deal.get (no caching unlike crm.deal.list)
echo "=== crm.deal.get #$dealId (fresh) — UF fields ===\n";
$deal = b24wh('crm.deal.get', ['id' => $dealId]);
$allFields = ['UF_CRM_1750775559215','UF_CRM_1750920048783','UF_CRM_1750920231839',
              'UF_CRM_1751015039070','UF_CRM_1752501717195'];
foreach ($allFields as $fn) {
    $raw = $deal[$fn] ?? null;
    $ids = is_array($raw) ? array_filter(array_map('intval', $raw)) : [];
    echo "  $fn → " . (empty($ids) ? '(empty)' : implode(', ', $ids)) . "\n";
}

// 2. booking.v1.booking.list for this deal
echo "\n=== booking.v1.booking.list (entityTypeId=2, entityId=$dealId) ===\n";
try {
    $r = b24wh('booking.v1.booking.list', [
        'filter' => ['entityTypeId' => 2, 'entityId' => $dealId],
    ]);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 3. booking.v1.booking.list without filter (all recent bookings)
echo "\n=== booking.v1.booking.list (all, limit 5) ===\n";
try {
    $r = b24wh('booking.v1.booking.list', ['limit' => 5]);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
