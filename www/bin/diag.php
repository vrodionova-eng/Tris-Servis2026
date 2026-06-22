<?php
// Diagnostic: scan deals, show calendar event SECT_IDs to find technician events.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

$techSections = [35, 37, 39, 41, 43]; // Тусюк, Козлянко, Муха, Кузавко, Сержанов
$select = array_merge(['ID', 'TITLE'], B24_BOOKING_FIELDS);
$checked = 0;
$found = [];
$start = 0;

while ($start < 2000) {
    $items = b24wh('crm.deal.list', [
        'order'  => ['ID' => 'DESC'],
        'select' => $select,
        'start'  => $start,
    ]);
    if (!is_array($items) || empty($items)) break;

    foreach ($items as $deal) {
        // Collect event IDs from all booking fields
        $eventIds = [];
        foreach (B24_BOOKING_FIELDS as $f) {
            $raw = $deal[$f] ?? null;
            if (!is_array($raw) || empty($raw)) continue;
            foreach ($raw as $id) {
                if ((int)$id > 0) $eventIds[] = (int)$id;
            }
        }
        if (empty($eventIds)) continue;

        $checked++;
        // Check each event
        foreach ($eventIds as $eid) {
            try {
                $ev = b24wh('calendar.event.getbyid', ['id' => $eid]);
            } catch (Throwable $e) { continue; }
            if (empty($ev)) continue;

            $sectId = (int)($ev['SECT_ID'] ?? 0);
            $dateFrom = $ev['DATE_FROM'] ?? '?';
            $isTech = in_array($sectId, $techSections);
            echo "Deal #{$deal['ID']} event=$eid SECT_ID=$sectId DATE_FROM=$dateFrom"
                . ($isTech ? ' ← TECH' : '') . "\n";

            if ($isTech) $found[] = $eid;
        }

        if (count($found) >= 3) break 2; // found enough
    }
    $start += 50;
}

echo "\nChecked $checked deals with booking fields.\n";
echo "Tech events found: " . count($found) . "\n";
