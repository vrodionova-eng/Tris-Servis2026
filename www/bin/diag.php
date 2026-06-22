<?php
// Diagnostic: inspect new events 499, 501, 503 from deal #887 booking.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Show raw calendar events for the new booking
echo "=== New booking events (499, 501, 503) ===\n";
foreach ([499, 501, 503] as $eid) {
    echo "--- calendar.event.getbyid($eid) ---\n";
    try {
        $ev = b24wh('calendar.event.getbyid', ['id' => $eid]);
        if (empty($ev) || !isset($ev['ID'])) {
            echo "  null/deleted\n\n";
        } else {
            printf("  SECT_ID=%-4s OWNER_ID=%-4s DATE_FROM=%-22s DATE_TO=%-22s\n",
                $ev['SECT_ID']   ?? '?',
                $ev['OWNER_ID']  ?? '?',
                $ev['DATE_FROM'] ?? '?',
                $ev['DATE_TO']   ?? '?'
            );
            echo "  NAME=" . ($ev['NAME'] ?? '') . "\n";
            echo "\n";
        }
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n\n";
    }
}

// 2. Try user.get for owner IDs found above (check if user scope works)
echo "=== user.get test (user IDs from events) ===\n";
foreach ([499, 501, 503] as $eid) {
    try {
        $ev = b24wh('calendar.event.getbyid', ['id' => $eid]);
        $ownerId = (string)($ev['OWNER_ID'] ?? '');
        if ($ownerId === '' || $ownerId === '0') continue;
        $users = b24wh('user.get', ['ID' => $ownerId]);
        $u = is_array($users) ? ($users[0] ?? null) : null;
        printf("  OWNER_ID=%-4s → LAST_NAME=%-15s NAME=%s\n",
            $ownerId,
            $u['LAST_NAME'] ?? '?',
            $u['NAME'] ?? '?'
        );
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// 3. Verify crm.deal.list also returns 499,501,503 now (caching check)
echo "\n=== crm.deal.list #887 — UF_CRM_1751015039070 ===\n";
$deals = b24wh('crm.deal.list', [
    'filter' => ['ID' => 887],
    'select' => ['ID', 'UF_CRM_1751015039070'],
]);
$raw = $deals[0]['UF_CRM_1751015039070'] ?? null;
echo "  Value: " . json_encode($raw) . "\n";
