<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/b24.php';

/**
 * Load resource maps from crm.deal.fields (cached).
 *
 * Returns [
 *   'sections' => ['calSectionId' => 'Surname'],  // resource section bookings
 *   'users'    => ['b24UserId'    => 'Surname'],  // employee (personal calendar) bookings
 * ]
 */
function loadResourceNames(): array
{
    $cached = storeRead(RESOURCE_NAMES_FILE);
    // Old cache format was a flat array; new format has 'sections' key.
    if (is_array($cached) && isset($cached['sections'])) return $cached;

    $fields   = b24wh('crm.deal.fields', []);
    $sections = [];
    $users    = [];

    foreach (B24_BOOKING_FIELDS as $fieldName) {
        $field    = $fields[$fieldName] ?? null;
        if (!$field) continue;
        $resource = $field['settings']['RESOURCES']['resource'] ?? [];

        foreach ($resource['SECTIONS'] ?? [] as $s) {
            $id      = (string)($s['ID']   ?? '');
            $rawName = trim((string)($s['NAME'] ?? ''));
            if ($id === '' || $rawName === '') continue;
            $sections[$id] = explode(' ', $rawName)[0];
        }

        // Employees selected as B24 users → events go to personal calendar (SECT_ID=0).
        // Identify them by OWNER_ID from the calendar event.
        foreach ($resource['USERS'] ?? [] as $u) {
            $id      = (string)($u['ID']   ?? '');
            $rawName = trim((string)($u['NAME'] ?? ''));
            if ($id === '' || $rawName === '') continue;
            $users[$id] = explode(' ', $rawName)[0];
        }
    }

    $maps = ['sections' => $sections, 'users' => $users];
    storeWrite(RESOURCE_NAMES_FILE, $maps);
    return $maps;
}

/**
 * Parse all booking fields from a deal.
 * Each UF resourcebooking field stores an array of calendar event IDs.
 * We fetch each event via calendar.event.getbyid to get DATE_FROM + technician identity.
 *
 * Technician is identified two ways:
 *  - SECT_ID ≠ 0 → resource section booking → look up in $resourceMaps['sections']
 *  - SECT_ID = 0 → personal calendar booking → look up OWNER_ID in $resourceMaps['users']
 *
 * @param array $deal         Deal data from crm.deal.list (uppercase field names)
 * @param array $resourceMaps ['sections' => [sectId => surname], 'users' => [userId => surname]]
 * @return array              [{date: 'DD.MM.YYYY', tech: 'Surname'}, ...]
 */
function parseBookings(array $deal, array $resourceMaps): array
{
    $sections = $resourceMaps['sections'] ?? [];
    $users    = $resourceMaps['users']    ?? [];

    $eventIds = [];
    foreach (B24_BOOKING_FIELDS as $field) {
        $raw = $deal[$field] ?? null;
        if (!is_array($raw) || empty($raw)) continue;
        foreach ($raw as $id) {
            $eventId = (int)$id;
            if ($eventId > 0) $eventIds[] = $eventId;
        }
    }

    $cells = [];
    foreach ($eventIds as $eventId) {
        try {
            $event = b24wh('calendar.event.getbyid', ['id' => $eventId]);
        } catch (Throwable $e) {
            continue;
        }
        if (empty($event)) continue;

        $sectId   = (string)($event['SECT_ID']   ?? '');
        $ownerId  = (string)($event['OWNER_ID']  ?? '');
        $dateFrom = (string)($event['DATE_FROM']  ?? '');
        $dateTo   = (string)($event['DATE_TO']    ?? '');

        // Resource section event → look up by section ID.
        // Personal calendar event (SECT_ID=0) → look up by calendar owner (B24 user ID).
        if ($sectId !== '' && $sectId !== '0') {
            $surname = $sections[$sectId] ?? null;
        } else {
            $surname = $users[$ownerId] ?? null;
        }

        if ($surname === null || !isset(TECH_COLUMNS[$surname])) continue;

        $fromTs = strtotime($dateFrom);
        if ($fromTs === false || $fromTs <= 0) continue;

        $days = 1;
        if ($dateTo !== '') {
            $toTs = strtotime($dateTo);
            if ($toTs !== false && $toTs > $fromTs) {
                $days = max(1, (int)ceil(($toTs - $fromTs) / 86400));
            }
        }

        for ($d = 0; $d < $days; $d++) {
            $cells[] = ['date' => date('d.m.Y', $fromTs + $d * 86400), 'tech' => $surname];
        }
    }

    // Deduplicate
    $seen   = [];
    $unique = [];
    foreach ($cells as $c) {
        $k = $c['date'] . '|' . $c['tech'];
        if (!isset($seen[$k])) {
            $seen[$k] = true;
            $unique[] = $c;
        }
    }
    return $unique;
}

/**
 * Build richText content for a cell: all deals currently at {date, tech}.
 * Returns ['text' => '...', 'runs' => [...]] or null if the cell is empty.
 *
 * @param string $date      'DD.MM.YYYY'
 * @param string $tech      Surname key from TECH_COLUMNS
 * @param array  $dealCells ['deal_id' => [{date,tech}, ...]]
 * @param array  $dealInfo  ['deal_id' => ['title' => ..., 'url' => ...]]
 */
function buildRichText(string $date, string $tech, array $dealCells, array $dealInfo): ?array
{
    $dealsHere = [];
    foreach ($dealCells as $dealId => $positions) {
        foreach ((array)$positions as $pos) {
            if (($pos['date'] ?? '') === $date && ($pos['tech'] ?? '') === $tech) {
                $dealsHere[] = (string)$dealId;
                break;
            }
        }
    }

    if (empty($dealsHere)) return null;

    $text = '';
    $runs = [];

    foreach ($dealsHere as $dealId) {
        $info  = $dealInfo[$dealId] ?? ['title' => '#' . $dealId, 'url' => ''];
        $label = (string)($info['title'] ?? '#' . $dealId);
        $url   = (string)($info['url']   ?? '');

        if ($text !== '') $text .= "\n";

        if ($url !== '') {
            $runs[] = [
                'startIndex' => mb_strlen($text, 'UTF-8'),
                'format'     => ['link' => ['uri' => $url]],
            ];
        }
        $text .= $label;
    }

    return ['text' => $text, 'runs' => $runs];
}

/**
 * Find the row number where a new date should be inserted (sorted order).
 * Returns the 1-based row to pass to insertDateRow().
 *
 * @param string $date       'DD.MM.YYYY'
 * @param array  $dateToRow  ['DD.MM.YYYY' => rowNum]
 */
function findInsertRow(string $date, array $dateToRow): int
{
    $toTs = static function(string $d): int {
        $p = explode('.', $d);
        return count($p) === 3
            ? (int)mktime(0, 0, 0, (int)$p[1], (int)$p[0], (int)$p[2])
            : 0;
    };

    $newTs = $toTs($date);
    // Default: append after the last existing row
    $insertPos = empty($dateToRow) ? 2 : max(array_values($dateToRow)) + 1;

    foreach ($dateToRow as $d => $row) {
        if ($toTs($d) > $newTs && $row < $insertPos) {
            $insertPos = $row;
        }
    }

    return $insertPos;
}
