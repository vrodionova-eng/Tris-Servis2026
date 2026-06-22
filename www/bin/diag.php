<?php
// Diagnostic: find correct calendar type for resource sections,
// probe next event IDs after 503, and scan all types for June events.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

$DEAL_ID = 887;

// 1. Deal state
echo "=== crm.deal.get #$DEAL_ID ===\n";
try {
    $deal = b24wh('crm.deal.get', ['id' => $DEAL_ID]);
    echo "  DATE_MODIFY: " . ($deal['DATE_MODIFY'] ?? '?') . "\n";
    echo "  UF_CRM_1751015039070 = " . json_encode($deal['UF_CRM_1751015039070'] ?? null) . "\n";
    echo "  UF_CRM_1752501717195 = " . json_encode($deal['UF_CRM_1752501717195'] ?? null) . "\n";
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    $deal = [];
}

// 2. KEY TEST: does type='group' find event 501 in MARCH for sect 29?
// If NO → wrong type → explains why June search returns empty.
echo "\n=== KEY TEST: Белый Largus (sect=29) in March 2026 ===\n";
foreach (['group', 'company_calendar', 'user'] as $t) {
    try {
        $evs = b24wh('calendar.event.get', [
            'type'    => $t,
            'ownerId' => 29,
            'from'    => '2026-03-01',
            'to'      => '2026-03-31',
        ]);
        $cnt = count((array)$evs);
        echo "  type='$t' ownerId=29 March → $cnt events";
        foreach ((array)$evs as $ev) {
            echo "\n    ID=" . ($ev['ID'] ?? '?') . " DATE_FROM=" . ($ev['DATE_FROM'] ?? '?') . " NAME=" . ($ev['NAME'] ?? '');
        }
        echo "\n";
    } catch (Throwable $e) {
        echo "  type='$t' → ERROR: " . $e->getMessage() . "\n";
    }
}

// 3. calendar.section.get for known resource section IDs (try all types)
echo "\n=== calendar.section.get for sect 29, 31, 39 ===\n";
foreach ([29, 31, 39] as $sid) {
    foreach (['group', 'company_calendar'] as $t) {
        try {
            $sects = b24wh('calendar.section.get', ['type' => $t, 'ownerId' => $sid]);
            if (!empty($sects)) {
                echo "  type='$t' ownerId=$sid → " . json_encode($sects[0] ?? $sects) . "\n";
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}
// Also try without ownerId filter — get all accessible sections
echo "  (all sections via type='company_calendar' without ownerId):\n";
try {
    $all = b24wh('calendar.section.get', ['type' => 'company_calendar']);
    foreach ((array)$all as $s) {
        printf("    ID=%-5s NAME=%s\n", $s['ID'] ?? '?', $s['NAME'] ?? '');
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 4. Probe event IDs 504–525
echo "\n=== calendar.event.getbyid probe 504-525 ===\n";
for ($i = 504; $i <= 525; $i++) {
    try {
        $ev = b24wh('calendar.event.getbyid', ['id' => $i]);
        if (!empty($ev)) {
            printf("  event %-5s SECT_ID=%-4s OWNER_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                $i, $ev['SECT_ID'] ?? '?', $ev['OWNER_ID'] ?? '?', $ev['DATE_FROM'] ?? '?', $ev['NAME'] ?? '');
        }
    } catch (Throwable $e) {
        // skip
    }
}
echo "  (done — null events skipped)\n";

// 5. Серый Largus sects (31, 49) — try all types for June 2026
echo "\n=== Серый Largus sects 31,49 — June 2026 — all types ===\n";
foreach ([31, 49] as $sid) {
    foreach (['group', 'company_calendar', 'user'] as $t) {
        try {
            $evs = b24wh('calendar.event.get', [
                'type'    => $t,
                'ownerId' => $sid,
                'from'    => '2026-06-01',
                'to'      => '2026-06-30',
            ]);
            $cnt = count((array)$evs);
            if ($cnt > 0) {
                echo "  type='$t' sid=$sid → $cnt events:\n";
                foreach ((array)$evs as $ev) {
                    echo "    ID=" . ($ev['ID'] ?? '?') . " DATE_FROM=" . ($ev['DATE_FROM'] ?? '?') . " NAME=" . ($ev['NAME'] ?? '') . "\n";
                }
            }
        } catch (Throwable $e) {
            // skip
        }
    }
}
echo "  (empty types omitted)\n";
