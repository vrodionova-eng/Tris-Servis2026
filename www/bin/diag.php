<?php
// Diagnostic: find Серый Largus section, fetch its June 2026 events,
// and check ALL UF booking fields for deal #887.
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

// 1. crm.deal.fields — field titles + section names (find Серый Largus SECT_ID)
echo "=== Resource sections from crm.deal.fields ===\n";
$allSections = [];
try {
    $flds = b24wh('crm.deal.fields', []);
    foreach ($ALL_FIELDS as $fn) {
        $f = $flds[$fn] ?? null;
        if (!$f) { echo "  $fn → NOT FOUND\n"; continue; }
        $title = $f['title'] ?? '?';
        $sects = $f['settings']['RESOURCES']['resource']['SECTIONS'] ?? [];
        echo "  $fn → \"$title\"\n";
        foreach ($sects as $s) {
            $sid  = (string)($s['ID']   ?? '');
            $snam = (string)($s['NAME'] ?? '');
            echo "    sect_id=$sid  name=$snam\n";
            $allSections[$sid] = $snam;
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 2. ALL booking fields for deal #887 (via crm.deal.get)
echo "\n=== crm.deal.get #$DEAL_ID — all booking fields ===\n";
$deal = [];
try {
    $deal = b24wh('crm.deal.get', ['id' => $DEAL_ID]);
    foreach ($ALL_FIELDS as $fn) {
        $val = $deal[$fn] ?? null;
        echo "  $fn = " . json_encode($val) . "\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 3. Fetch each event ID found in any field
echo "\n=== calendar.event.getbyid (all field events) ===\n";
$seen = [];
foreach ($ALL_FIELDS as $fn) {
    $ids = $deal[$fn] ?? null;
    if (!is_array($ids) || empty($ids)) continue;
    foreach ($ids as $eid) {
        $eid = (int)$eid;
        if ($eid <= 0 || isset($seen[$eid])) continue;
        $seen[$eid] = true;
        try {
            $ev = b24wh('calendar.event.getbyid', ['id' => $eid]);
            if (empty($ev)) {
                echo "  [$fn] event $eid → null/deleted\n";
            } else {
                printf("  [$fn] event %-5s SECT_ID=%-4s OWNER_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                    $eid, $ev['SECT_ID'] ?? '?', $ev['OWNER_ID'] ?? '?', $ev['DATE_FROM'] ?? '?', $ev['NAME'] ?? '');
            }
        } catch (Throwable $e) {
            echo "  [$fn] event $eid → ERROR: " . $e->getMessage() . "\n";
        }
    }
}

// 4. Search ALL known car sections for events around June 24, 2026
echo "\n=== calendar.event.get for each resource section (June 2026) ===\n";
foreach ($allSections as $sectId => $sectName) {
    echo "-- sect_id=$sectId \"$sectName\" --\n";
    try {
        $evs = b24wh('calendar.event.get', [
            'type'    => 'group',
            'ownerId' => $sectId,
            'from'    => '2026-06-01',
            'to'      => '2026-06-30',
        ]);
        if (empty($evs)) {
            echo "  (no events)\n";
        } else {
            foreach ($evs as $ev) {
                printf("  ID=%-5s DATE_FROM=%-22s OWNER_ID=%-4s NAME=%s\n",
                    $ev['ID'] ?? '?', $ev['DATE_FROM'] ?? '?', $ev['OWNER_ID'] ?? '?', $ev['NAME'] ?? '');
            }
        }
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}
