<?php
// Diagnostic: scan recent deals with booking fields and show calendar event SECT_IDs.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

echo "=== Scanning deals with booking fields ===\n";
echo "Fields: " . implode(', ', B24_BOOKING_FIELDS) . "\n\n";

$start = 0;
$total = 0;
$withBookings = 0;

do {
    $deals = b24wh('crm.deal.list', [
        'select' => array_merge(['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID'], B24_BOOKING_FIELDS),
        'order'  => ['DATE_MODIFY' => 'DESC'],
        'start'  => $start,
    ]);
    if (!is_array($deals)) break;

    foreach ($deals as $deal) {
        $total++;
        $dealId = $deal['ID'] ?? '?';

        $eventIds = [];
        foreach (B24_BOOKING_FIELDS as $fn) {
            $raw = $deal[$fn] ?? null;
            if (is_array($raw)) {
                foreach ($raw as $id) {
                    $eid = (int)$id;
                    if ($eid > 0) $eventIds[] = $eid;
                }
            }
        }

        if (empty($eventIds)) continue;
        $withBookings++;

        echo "Deal #{$dealId} [{$deal['TITLE']}] cat={$deal['CATEGORY_ID']} stage={$deal['STAGE_ID']}\n";
        echo "  Events: " . implode(', ', $eventIds) . "\n";

        foreach ($eventIds as $eid) {
            try {
                $ev = b24wh('calendar.event.getbyid', ['id' => $eid]);
                if (empty($ev) || !isset($ev['ID'])) {
                    echo "    event $eid → null/deleted\n";
                } else {
                    printf("    event %-5s SECT_ID=%-4s DATE_FROM=%-20s NAME=%s\n",
                        $eid,
                        $ev['SECT_ID'] ?? '?',
                        $ev['DATE_FROM'] ?? '?',
                        $ev['NAME'] ?? ''
                    );
                }
            } catch (Throwable $e) {
                echo "    event $eid → ERROR: {$e->getMessage()}\n";
            }
        }
        echo "\n";

        if ($withBookings >= 10) break 2; // show first 10 deals with bookings
    }

    $start += 50;
} while (count($deals) === 50 && $start < 500);

echo "=== Scanned $total deals, found $withBookings with bookings ===\n";
