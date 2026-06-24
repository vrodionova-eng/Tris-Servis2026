<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/b24.php';

/**
 * Load B24 active users whose LAST_NAME matches a spreadsheet column header.
 * Returns [userId(int) => surname(string)].
 */
function loadTechUsers(array $columnMap): array
{
    $resp = b24wh('user.get', ['ACTIVE' => true]);
    $map  = [];
    foreach ((array)$resp as $u) {
        $surname = trim((string)($u['LAST_NAME'] ?? ''));
        if ($surname !== '' && isset($columnMap[$surname])) {
            $map[(int)($u['ID'])] = $surname;
        }
    }
    return $map;
}

/**
 * Fetch personal-calendar booking events for the given users in [from..to].
 * Filters by EVENT_TYPE="#resourcebooking#".
 *
 * Returns array of:
 *   ['date'    => 'DD.MM.YYYY',     // from DATE_FROM
 *    'dateTo'  => 'DD.MM.YYYY',     // from DATE_TO (may equal date for same-day)
 *    'surname' => 'Муха',
 *    'title'   => 'Ч/К ул.Молодежная...']  // stripped from NAME
 */
function fetchTechBookings(array $techUsers, string $from, string $to): array
{
    $result = [];
    foreach ($techUsers as $userId => $surname) {
        try {
            $events = b24wh('calendar.event.get', [
                'type'    => 'user',
                'ownerId' => (string)$userId,
                'from'    => $from,
                'to'      => $to,
            ]);
        } catch (Throwable $e) {
            continue;
        }
        foreach ((array)$events as $event) {
            if (($event['EVENT_TYPE'] ?? '') !== '#resourcebooking#') continue;
            $rawFrom = trim((string)($event['DATE_FROM'] ?? ''));
            if ($rawFrom === '') continue;

            // DATE_FROM: "24.06.2026 12:00:00" → keep "DD.MM.YYYY"
            $date = substr($rawFrom, 0, 10);
            if (!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) continue;

            $rawTo   = trim((string)($event['DATE_TO'] ?? ''));
            $dateTo  = substr($rawTo, 0, 10);
            if (!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $dateTo)) $dateTo = $date;

            // Strip "Бронирование: " prefix to get deal title
            $name  = (string)($event['NAME'] ?? '');
            $title = trim(preg_replace('/^Бронирование:\s*/u', '', $name));

            $result[] = [
                'date'    => $date,
                'dateTo'  => $dateTo,
                'surname' => $surname,
                'title'   => $title,
            ];
        }
    }
    return $result;
}

/**
 * Read spreadsheet row-1 headers, build surname → column letter map.
 * E.g. ['Тусюк' => 'B', 'Муха' => 'D', 'Юрченков' => 'F'].
 * Matching uses the first word (surname) of each header cell.
 */
function loadColumnMap(GoogleSheets $sheets): array
{
    $map = [];
    foreach ($sheets->readHeaderRow() as $col => $header) {
        $surname = explode(' ', $header)[0];
        if ($surname !== '') {
            $map[$surname] = $col;
        }
    }
    return $map;
}

/**
 * Find the 1-based row where a new date should be inserted (sorted ascending order).
 *
 * @param string $date      'DD.MM.YYYY'
 * @param array  $dateToRow ['DD.MM.YYYY' => rowNum]
 */
function findInsertRow(string $date, array $dateToRow): int
{
    $toTs = static function(string $d): int {
        $p = explode('.', $d);
        return count($p) === 3
            ? (int)mktime(0, 0, 0, (int)$p[1], (int)$p[0], (int)$p[2])
            : 0;
    };

    $newTs     = $toTs($date);
    $insertPos = empty($dateToRow) ? 2 : max(array_values($dateToRow)) + 1;

    foreach ($dateToRow as $d => $row) {
        if ($toTs($d) > $newTs && $row < $insertPos) {
            $insertPos = $row;
        }
    }
    return $insertPos;
}

/** Convert 'DD.MM.YYYY' → 'YYYY-MM' month key. */
function dateToMonthKey(string $date): string
{
    $p = explode('.', $date);
    return count($p) === 3 ? $p[2] . '-' . $p[1] : '';
}

/** Return Russian month label for a date: 'DD.MM.YYYY' → 'Июнь 2026'. */
function ruMonthLabel(string $date): string
{
    static $names = ['','Январь','Февраль','Март','Апрель','Май','Июнь',
                     'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    $p = explode('.', $date);
    return count($p) === 3 ? ($names[(int)$p[1]] . ' ' . $p[2]) : $date;
}

/**
 * Expand a booking (possibly multi-day) into individual date strings.
 *
 * @return string[]  ['DD.MM.YYYY', ...]
 */
function expandDates(string $dateFrom, string $dateTo): array
{
    $p    = explode('.', $dateFrom);
    $from = mktime(0, 0, 0, (int)$p[1], (int)$p[0], (int)$p[2]);
    $q    = explode('.', $dateTo);
    $to   = mktime(0, 0, 0, (int)$q[1], (int)$q[0], (int)$q[2]);
    if ($to < $from) $to = $from;

    $days = [];
    for ($ts = $from; $ts <= $to; $ts += 86400) {
        $days[] = date('d.m.Y', $ts);
    }
    return $days ?: [$dateFrom];
}
