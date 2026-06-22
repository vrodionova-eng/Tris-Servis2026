<?php
// Diagnostic: search Муха's personal calendar for June 2026 bookings.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Find technicians by last name
$surnames = ['Муха', 'Юрченков', 'Тусюк', 'Козлянко'];
echo "=== user.get by last names ===\n";
$techUsers = []; // surname => [ID, NAME, LAST_NAME]
foreach ($surnames as $sn) {
    try {
        $r = b24wh('user.get', ['filter' => ['LAST_NAME' => $sn]]);
        if (!empty($r)) {
            foreach ($r as $u) {
                printf("  %-12s → ID=%-4s  %s %s\n", $sn, $u['ID'], $u['NAME'] ?? '', $u['LAST_NAME'] ?? '');
                $techUsers[$sn] = $u;
            }
        } else {
            echo "  $sn → not found\n";
        }
    } catch (Throwable $e) {
        echo "  $sn → ERROR: " . $e->getMessage() . "\n";
    }
}

// 2. Check personal calendar events for each technician (June 2026)
echo "\n=== calendar.event.get (personal, June 2026) ===\n";
foreach ($techUsers as $sn => $u) {
    $userId = $u['ID'];
    echo "-- $sn (userId=$userId) --\n";
    try {
        $evs = b24wh('calendar.event.get', [
            'type'    => 'user',
            'ownerId' => $userId,
            'from'    => '2026-06-01',
            'to'      => '2026-06-30',
        ]);
        if (empty($evs)) {
            echo "  (no events)\n";
        } else {
            foreach ($evs as $ev) {
                printf("  ID=%-5s SECT_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
                    $ev['ID']        ?? '?',
                    $ev['SECT_ID']   ?? '?',
                    $ev['DATE_FROM'] ?? '?',
                    $ev['NAME']      ?? ''
                );
            }
        }
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
