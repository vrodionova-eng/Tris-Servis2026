<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/b24.php';

/**
 * Load calendar section ID → surname map from crm.deal.fields (cached).
 * The resourcebooking UF field stores calendar event IDs; each event has a SECT_ID
 * that maps to a technician/resource. We build this map from field metadata.
 *
 * Returns ['section_id' => 'Surname'].
 */
function loadResourceNames(): array
{
    $cached = storeRead(RESOURCE_NAMES_FILE);
    if (is_array($cached) && !empty($cached)) return $cached;

    $fields = b24wh('crm.deal.fields', []);
    $names  = [];

    foreach (B24_BOOKING_FIELDS as $fieldName) {
        $field    = $fields[$fieldName] ?? null;
        if (!$field) continue;
        $sections = $field['settings']['RESOURCES']['resource']['SECTIONS'] ?? [];
        foreach ($sections as $s) {
            $id      = (string)($s['ID']   ?? '');
            $rawName = trim((string)($s['NAME'] ?? ''));
            if ($id === '' || $rawName === '') continue;
            // First word is the surname (Russian naming: Фамилия Имя)
            $surname = explode(' ', $rawName)[0];
            $names[$id] = $surname;
        }
    }

    storeWrite(RESOURCE_NAMES_FILE, $names);
    return $names;
}

/**
 * Parse all booking fields from a deal.
 * Each UF resourcebooking field stores an array of calendar event IDs.
 * We fetch each event via calendar.event.getbyid to get DATE_FROM + SECT_ID.
 *
 * @param array $deal          Deal data from crm.deal.list
 * @param array $resourceNames ['sect_id' => 'Surname']
 * @return array               [{date: 'DD.MM.YYYY', tech: 'Surname'}, ...]
 */
function parseBookings(array $deal, array $resourceNames): array
{
    $cells = [];

    // Collect all calendar event IDs from all booking fields
    $eventIds = [];
    foreach (B24_BOOKING_FIELDS as $field) {
        $raw = $deal[$field] ?? null;
        if (!is_array($raw) || empty($raw)) continue;
        foreach ($raw as $id) {
            $eventId = (int)$id;
            if ($eventId > 0) $eventIds[] = $eventId;
        }
    }

    foreach ($eventIds as $eventId) {
        try {
            $event = b24wh('calendar.event.getbyid', ['id' => $eventId]);
        } catch (Throwable $e) {
            continue;
        }
        if (empty($event)) continue;

        $sectId  = (string)($event['SECT_ID']   ?? '');
        $dateFrom = (string)($event['DATE_FROM'] ?? '');
        $dateTo   = (string)($event['DATE_TO']   ?? '');

        $surname = $resourceNames[$sectId] ?? null;
        if ($surname === null || !isset(TECH_COLUMNS[$surname])) continue;

        $fromTs = strtotime($dateFrom);
        if ($fromTs === false || $fromTs <= 0) continue;

        if ($dateTo !== '') {
            $toTs = strtotime($dateTo);
            $days = ($toTs !== false && $toTs > $fromTs)
                ? max(1, (int)ceil(($toTs - $fromTs) / 86400))
                : 1;
        } else {
            $days = 1;
        }

        for ($d = 0; $d < $days; $d++) {
            $cells[] = [
                'date' => date('d.m.Y', $fromTs + $d * 86400),
                'tech' => $surname,
            ];
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
