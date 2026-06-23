<?php
// Diagnostic: found the booking via calendar.resource.booking.list (resource IDs).
// Now find employee (Сотрудники) bookings for deal #887.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// Tech user IDs: Муха=67, Юрченков=71, Тусюк=57, Козлянко=65
// Tech resource IDs: Тусюк=35, Козлянко=37, Муха=39, Кузавко=41, Сержанов=43
// ALL resource IDs from calendar.resource.list: 20,22,29,31,33,35,37,39,41,43,45,47,49,51,73
$allResourceIds = [20, 22, 29, 31, 33, 35, 37, 39, 41, 43, 45, 47, 49, 51, 73];

// 1. calendar.event.getbyid(613) — the event we found for Серый Largus June 24
echo "=== calendar.event.getbyid(613) ===\n";
try {
    $ev = b24wh('calendar.event.getbyid', ['id' => 613]);
    echo json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 2. calendar.resource.booking.list with user IDs as resourceTypeIdList
// Maybe employees booked as users (not resource sections) use their user IDs
echo "\n=== calendar.resource.booking.list (resourceTypeIdList = user IDs [57,65,67,71]) June 2026 ===\n";
try {
    $r = b24wh('calendar.resource.booking.list', [
        'filter' => [
            'resourceTypeIdList' => [57, 65, 67, 71],
            'dateFrom'           => '2026-06-01',
            'dateTo'             => '2026-06-30',
        ],
    ]);
    $list = is_array($r) ? $r : [];
    echo "  count: " . count($list) . "\n";
    foreach ($list as $b) {
        printf("  ID=%-5s SECTION_ID=%-4s DATE_FROM=%-22s RESOURCE_BOOKING_ID=%-6s NAME=%s\n",
            $b['ID'] ?? '?', $b['SECTION_ID'] ?? '?', $b['DATE_FROM'] ?? '?',
            $b['RESOURCE_BOOKING_ID'] ?? '?', mb_substr($b['NAME'] ?? '', 0, 50));
    }
    if (empty($list)) echo "  (empty)\n";
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 3. calendar.resource.booking.list ALL resource IDs, ALL dates — find employee events
echo "\n=== calendar.resource.booking.list (ALL IDs, June 2026, full output) ===\n";
try {
    $r = b24wh('calendar.resource.booking.list', [
        'filter' => [
            'resourceTypeIdList' => $allResourceIds,
            'dateFrom'           => '2026-06-01',
            'dateTo'             => '2026-06-30',
        ],
    ]);
    $list = is_array($r) ? $r : [];
    echo "  count: " . count($list) . "\n";
    foreach ($list as $b) {
        printf("  ID=%-5s SECTION_ID=%-4s DATE_FROM=%-22s RESOURCE_BOOKING_ID=%-6s NAME=%s\n",
            $b['ID'] ?? '?', $b['SECTION_ID'] ?? '?', $b['DATE_FROM'] ?? '?',
            $b['RESOURCE_BOOKING_ID'] ?? '?', mb_substr($b['NAME'] ?? '', 0, 50));
        if (!empty($b['ATTENDEE_LIST'])) {
            echo "    ATTENDEES: " . json_encode($b['ATTENDEE_LIST'], JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 4. calendar.event.get (type=user) for Муха and Юрченков — now calendar is accessible
echo "\n=== calendar.event.get (personal) Муха(67) + Юрченков(71) June 2026 ===\n";
foreach ([67 => 'Муха', 71 => 'Юрченков'] as $uid => $name) {
    try {
        $evs = b24wh('calendar.event.get', [
            'type'    => 'user',
            'ownerId' => $uid,
            'from'    => '2026-06-01',
            'to'      => '2026-06-30',
        ]);
        $cnt = count((array)$evs);
        echo "  $name (uid=$uid): $cnt events\n";
        foreach ((array)$evs as $ev) {
            printf("    ID=%-5s SECT_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                $ev['ID'] ?? '?', $ev['SECT_ID'] ?? '?', $ev['DATE_FROM'] ?? '?',
                mb_substr($ev['NAME'] ?? '', 0, 50));
        }
    } catch (Throwable $e) {
        echo "  $name → ERROR: " . $e->getMessage() . "\n";
    }
}

// 5. Search by RESOURCE_BOOKING_ID=499 — find all events for this booking slot
echo "\n=== All events linked to RESOURCE_BOOKING_ID=499 (search 600-700) ===\n";
$found = [];
for ($i = 600; $i <= 700; $i++) {
    try {
        $ev = b24wh('calendar.event.getbyid', ['id' => $i]);
        if (!empty($ev) && ($ev['DATE_FROM'] ?? '') !== '' && ($ev['SECT_ID'] ?? '') !== '') {
            $found[] = $ev;
            printf("  event %-5s SECT_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                $i, $ev['SECT_ID'] ?? '?', $ev['DATE_FROM'] ?? '?',
                mb_substr($ev['NAME'] ?? '', 0, 50));
        }
    } catch (Throwable $e) {}
}
if (empty($found)) echo "  (no active events in 600-700)\n";
