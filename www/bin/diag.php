<?php
// Diagnostic: find correct ownerId for resource booking calendar sections.
// KEY INSIGHT: calendar.event.get ownerId is the CALENDAR OWNER (company/user),
// not the section ID. Resource booking sections are company-level → try ownerId=1.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Try calendar.event.get with ownerId=1 (company), section=[29] to find event 501 (March)
// If this works → we found the right call for resource booking events.
echo "=== KEY: calendar.event.get company ownerId=1, section=[29], March 2026 ===\n";
foreach (['company_calendar', 'group', 'user'] as $t) {
    try {
        $evs = b24wh('calendar.event.get', [
            'type'    => $t,
            'ownerId' => 1,
            'section' => [29],
            'from'    => '2026-03-01',
            'to'      => '2026-03-31',
        ]);
        $cnt = count((array)$evs);
        echo "  type='$t' ownerId=1 section=[29] March → $cnt events";
        foreach ((array)$evs as $ev) {
            echo "\n    ID=" . ($ev['ID'] ?? '?') . " DATE_FROM=" . ($ev['DATE_FROM'] ?? '?') . " NAME=" . ($ev['NAME'] ?? '');
        }
        echo "\n";
    } catch (Throwable $e) {
        echo "  type='$t' ownerId=1 → ERROR: " . $e->getMessage() . "\n";
    }
}

// 2. Try with ownerId=0 (system events have OWNER_ID=0)
echo "\n=== calendar.event.get ownerId=0, section=[29], March 2026 ===\n";
foreach (['company_calendar', 'group'] as $t) {
    try {
        $evs = b24wh('calendar.event.get', [
            'type'    => $t,
            'ownerId' => 0,
            'section' => [29],
            'from'    => '2026-03-01',
            'to'      => '2026-03-31',
        ]);
        $cnt = count((array)$evs);
        echo "  type='$t' ownerId=0 section=[29] March → $cnt events";
        foreach ((array)$evs as $ev) {
            echo "\n    ID=" . ($ev['ID'] ?? '?') . " DATE_FROM=" . ($ev['DATE_FROM'] ?? '?') . " NAME=" . ($ev['NAME'] ?? '');
        }
        echo "\n";
    } catch (Throwable $e) {
        echo "  type='$t' ownerId=0 → ERROR: " . $e->getMessage() . "\n";
    }
}

// 3. Try calendar.event.get with NO section filter — just ownerId=1 company March
echo "\n=== calendar.event.get company ownerId=1, NO section filter, March 2026 ===\n";
try {
    $evs = b24wh('calendar.event.get', [
        'type'    => 'company_calendar',
        'ownerId' => 1,
        'from'    => '2026-03-01',
        'to'      => '2026-03-31',
    ]);
    $cnt = count((array)$evs);
    echo "  → $cnt events\n";
    foreach (array_slice((array)$evs, 0, 10) as $ev) {
        printf("  ID=%-5s SECT_ID=%-4s DATE_FROM=%-22s NAME=%s\n",
            $ev['ID'] ?? '?', $ev['SECT_ID'] ?? '?', $ev['DATE_FROM'] ?? '?', $ev['NAME'] ?? '');
    }
    if ($cnt > 10) echo "  ... (showing first 10 of $cnt)\n";
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 4. Try calendar.event.get with webhook user ID (77) as ownerId
echo "\n=== calendar.event.get type=user ownerId=77 (webhook user), March+June 2026 ===\n";
foreach (['2026-03-01/2026-03-31', '2026-06-01/2026-06-30'] as $range) {
    [$f, $t] = explode('/', $range);
    try {
        $evs = b24wh('calendar.event.get', [
            'type'    => 'user',
            'ownerId' => 77,
            'from'    => $f,
            'to'      => $t,
        ]);
        $cnt = count((array)$evs);
        echo "  user 77 $range → $cnt events";
        foreach ((array)$evs as $ev) {
            echo "\n    ID=" . ($ev['ID'] ?? '?') . " SECT=" . ($ev['SECT_ID'] ?? '?') . " DATE=" . ($ev['DATE_FROM'] ?? '?') . " NAME=" . ($ev['NAME'] ?? '');
        }
        echo "\n";
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// 5. calendar.section.get with ownerId=1 to find resource booking section type
echo "\n=== calendar.section.get type=company_calendar ownerId=1 ===\n";
try {
    $sects = b24wh('calendar.section.get', ['type' => 'company_calendar', 'ownerId' => 1]);
    if (empty($sects)) {
        echo "  (empty)\n";
    } else {
        foreach ((array)$sects as $s) {
            printf("  ID=%-5s NAME=%s\n", $s['ID'] ?? '?', $s['NAME'] ?? '');
        }
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
