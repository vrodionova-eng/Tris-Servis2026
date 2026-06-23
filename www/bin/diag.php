<?php
// Diagnostic: probe event IDs 526-800 to find June 2026 bookings.
// Also dump deal #887 DATE_MODIFY and all UF field values fresh.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

$DEAL_ID = 887;
$ALL_FIELDS = [
    'UF_CRM_1750775559215',
    'UF_CRM_1750920048783',
    'UF_CRM_1750920231839',
    'UF_CRM_1751015039070',
    'UF_CRM_1752501717195',
];

// 1. Deal state — DATE_MODIFY tells us when it was last saved
echo "=== crm.deal.get #$DEAL_ID ===\n";
try {
    $deal = b24wh('crm.deal.get', ['id' => $DEAL_ID]);
    echo "  DATE_MODIFY: " . ($deal['DATE_MODIFY'] ?? '?') . "\n";
    foreach ($ALL_FIELDS as $fn) {
        $val = $deal[$fn] ?? null;
        echo "  $fn = " . json_encode($val) . "\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 2. Probe event IDs 526–800 — find any June 2026 or Серый Largus events
echo "\n=== calendar.event.getbyid probe 526-800 (non-null only) ===\n";
$found = 0;
for ($i = 526; $i <= 800; $i++) {
    try {
        $ev = b24wh('calendar.event.getbyid', ['id' => $i]);
        if (!empty($ev)) {
            $found++;
            printf("  event %-5s SECT_ID=%-4s OWNER_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                $i,
                $ev['SECT_ID']   ?? '?',
                $ev['OWNER_ID']  ?? '?',
                $ev['DATE_FROM'] ?? '?',
                mb_substr((string)($ev['NAME'] ?? ''), 0, 60)
            );
        }
    } catch (Throwable $e) {
        // skip errors
    }
}
if ($found === 0) {
    echo "  (no active events in range 526-800)\n";
}
echo "  total found: $found\n";
