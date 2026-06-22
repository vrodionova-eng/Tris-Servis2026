<?php
// Diagnostic: check deal #887 booking state after saving.
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

// 1. Deal #887 — current UF field values + date modified
echo "=== crm.deal.get #$DEAL_ID ===\n";
$deal = [];
try {
    $deal = b24wh('crm.deal.get', ['id' => $DEAL_ID]);
    echo "  TITLE:       " . ($deal['TITLE'] ?? '?') . "\n";
    echo "  DATE_MODIFY: " . ($deal['DATE_MODIFY'] ?? '?') . "\n";
    foreach ($ALL_FIELDS as $fn) {
        $val = $deal[$fn] ?? null;
        echo "  $fn = " . json_encode($val) . "\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 2. Fetch all known event IDs + probe next 20 (504-523)
echo "\n=== calendar.event.getbyid (known + probe 504-523) ===\n";
$knownIds = [];
foreach ($ALL_FIELDS as $fn) {
    foreach ((array)($deal[$fn] ?? []) as $id) {
        $eid = (int)$id;
        if ($eid > 0) $knownIds[$eid] = true;
    }
}
// Probe next IDs after max known
$maxId = empty($knownIds) ? 503 : max(array_keys($knownIds));
for ($i = $maxId + 1; $i <= $maxId + 20; $i++) { $knownIds[$i] = true; }
ksort($knownIds);

foreach (array_keys($knownIds) as $eid) {
    try {
        $ev = b24wh('calendar.event.getbyid', ['id' => $eid]);
        if (empty($ev)) {
            echo "  event $eid → null\n";
        } else {
            printf("  event %-5s SECT_ID=%-4s OWNER_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                $eid, $ev['SECT_ID'] ?? '?', $ev['OWNER_ID'] ?? '?', $ev['DATE_FROM'] ?? '?', $ev['NAME'] ?? '');
        }
    } catch (Throwable $e) {
        echo "  event $eid → ERROR: " . $e->getMessage() . "\n";
    }
}

// 3. booking.v1.booking.list for deal #887
echo "\n=== booking.v1.booking.list (entityTypeId=2, entityId=$DEAL_ID) ===\n";
try {
    $bk = b24wh('booking.v1.booking.list', [
        'entityTypeId' => 2,
        'entityId'     => $DEAL_ID,
    ]);
    if (empty($bk) || empty($bk['bookings'] ?? $bk)) {
        echo "  (empty)\n";
        var_export($bk);
        echo "\n";
    } else {
        $list = $bk['bookings'] ?? $bk;
        foreach ((array)$list as $b) {
            $from = isset($b['from']) ? date('d.m.Y H:i', (int)$b['from']) : '?';
            $to   = isset($b['to'])   ? date('d.m.Y H:i', (int)$b['to'])   : '?';
            $rids = json_encode($b['resourceIds'] ?? []);
            echo "  booking id=" . ($b['id'] ?? '?') . " from=$from to=$to resources=$rids\n";
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 4. Серый Largus sections — events in June AND March (to verify API call format)
echo "\n=== calendar.event.get Серый Largus (sect 31,49) — June + March 2026 ===\n";
foreach ([31, 49] as $sid) {
    foreach (['2026-03-01/2026-03-31', '2026-06-01/2026-06-30'] as $range) {
        [$f, $t] = explode('/', $range);
        try {
            $evs = b24wh('calendar.event.get', ['type' => 'group', 'ownerId' => $sid, 'from' => $f, 'to' => $t]);
            $cnt = count((array)$evs);
            echo "  sect=$sid $range → $cnt events";
            if ($cnt > 0) {
                foreach ((array)$evs as $ev) {
                    echo "\n    ID=" . ($ev['ID'] ?? '?') . " DATE_FROM=" . ($ev['DATE_FROM'] ?? '?') . " NAME=" . ($ev['NAME'] ?? '');
                }
            }
            echo "\n";
        } catch (Throwable $e) {
            echo "  sect=$sid $range → ERROR: " . $e->getMessage() . "\n";
        }
    }
}
