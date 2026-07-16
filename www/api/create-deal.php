<?php
// API: создать сделку из Google Sheets формы + создать брони в календарях техников.
// Method: POST
// Body JSON:
// {
//   "name": "Название сделки",
//   "funnel": "Сервисное обслуживание" | "Плановое ТО",
//   "date": "YYYY-MM-DD",
//   "serviceTeam": ["Тусюк", ...],      // для Сервисного обслуживания
//   "partsTeam": ["Козляко", ...],
//   "to2Team1": ["Муха", ...],          // для Планового ТО
//   "to2Team2": ["Юрченков", ...]
// }
// Response JSON:
// { "success": true, "dealId": "123" }
// или
// { "success": false, "error": "..." }

declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/lib.php';
require_once __DIR__ . '/../api/b24.php';

// ── CORS / JSON headers ───────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function respond(bool $success, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function logError(string $msg): void {
    $dir = DATA_ROOT . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($dir . '/create-deal.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
}

// ── Read input ────────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    respond(false, ['error' => 'Invalid JSON']);
}

$name = trim((string)($payload['name'] ?? ''));
$dateRaw = trim((string)($payload['date'] ?? ''));
$funnel = trim((string)($payload['funnel'] ?? ''));

if ($name === '') respond(false, ['error' => 'name required']);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
    respond(false, ['error' => 'date must be YYYY-MM-DD']);
}
if ($funnel !== 'Сервисное обслуживание' && $funnel !== 'Плановое ТО') {
    respond(false, ['error' => 'funnel invalid']);
}

// Parse to DD.MM.YYYY for B24 event API
[$y, $m, $d] = explode('-', $dateRaw);
$dateB24 = "$d.$m.$y";
$dateFrom = "$dateB24 09:00:00";
$dateTo   = "$dateB24 18:00:00";

// ── Config ──────────────────────────────────────────────────────────────────────
const CATEGORIES = [
    'Сервисное обслуживание' => ['id' => 3, 'stage' => 'DEAL_STAGE_3:221'],
    'Плановое ТО'            => ['id' => 5, 'stage' => 'DEAL_STAGE_5:257'],
];

const FIELDS = [
    'serviceTeam' => 'UF_CRM_1750775559215',   // Сервисная бригада
    'partsTeam'   => 'UF_CRM_1751015039070',   // Сервисная бригада для замены запчастей
    'to2Team1'    => 'UF_CRM_1750920048783',   // Сервисная бригада ТО-1
    'to2Team2'    => 'UF_CRM_1750920231839',   // Сервисная бригада ТО-2
];

// Which fields apply to which funnel
const FUNNEL_FIELDS = [
    'Сервисное обслуживание' => ['serviceTeam', 'partsTeam'],
    'Плановое ТО'            => ['to2Team1', 'to2Team2'],
];

// ── Load users and map surname → userId ─────────────────────────────────────────
$userMap = [];
try {
    $users = b24wh('user.get', ['ACTIVE' => true]);
    foreach ((array)$users as $u) {
        $surname = trim((string)($u['LAST_NAME'] ?? ''));
        if ($surname !== '') {
            $userMap[$surname] = (int)$u['ID'];
        }
    }
} catch (Throwable $e) {
    logError('user.get error: ' . $e->getMessage());
    respond(false, ['error' => 'B24 user lookup failed']);
}

// ── Create deal ───────────────────────────────────────────────────────────────
$catConfig = CATEGORIES[$funnel];
$dealFields = [
    'TITLE'        => $name,
    'CATEGORY_ID'  => $catConfig['id'],
    'STAGE_ID'     => $catConfig['stage'],
];

$dealId = null;
try {
    $result = b24wh('crm.deal.add', ['fields' => $dealFields, 'params' => ['REGISTER_SONET_EVENT' => 'N']]);
    $dealId = (int)(is_array($result) ? ($result['ID'] ?? $result['id'] ?? 0) : 0);
} catch (Throwable $e) {
    logError('crm.deal.add error: ' . $e->getMessage());
    respond(false, ['error' => 'Failed to create deal: ' . $e->getMessage()]);
}

if ($dealId <= 0) {
    logError('crm.deal.add returned no ID: ' . json_encode($result ?? null, JSON_UNESCAPED_UNICODE));
    respond(false, ['error' => 'Failed to create deal']);
}

// ── Create bookings for each selected team member ─────────────────────────────
$updateFields = [];
$errors = [];

foreach (FUNNEL_FIELDS[$funnel] as $key) {
    $surnames = (array)($payload[$key] ?? []);
    if (empty($surnames)) continue;

    $bookingIds = [];
    foreach ($surnames as $surname) {
        $surname = trim((string)$surname);
        $userId = $userMap[$surname] ?? null;
        if ($userId === null) {
            $errors[] = "User not found: $surname";
            continue;
        }

        $eventName = 'Бронирование: ' . $name;
        try {
            $event = b24wh('calendar.event.add', [
                'type'        => 'user',
                'ownerId'     => $userId,
                'name'        => $eventName,
                'description' => $eventName,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'skip_time'   => 'Y',
                'event_type'  => '#resourcebooking#',
            ]);
            $eventId = (int)(is_array($event) ? ($event['ID'] ?? $event['id'] ?? 0) : 0);
            if ($eventId > 0) {
                $bookingIds[] = $eventId;
            } else {
                $errors[] = "calendar.event.add returned no ID for $surname";
            }
        } catch (Throwable $e) {
            logError('calendar.event.add error for ' . $surname . ': ' . $e->getMessage());
            $errors[] = 'Booking error for ' . $surname . ': ' . $e->getMessage();
        }
    }

    if (!empty($bookingIds)) {
        $updateFields[FIELDS[$key]] = $bookingIds;
    }
}

// ── Update deal with booking IDs ──────────────────────────────────────────────
if (!empty($updateFields)) {
    try {
        b24wh('crm.deal.update', ['id' => $dealId, 'fields' => $updateFields]);
    } catch (Throwable $e) {
        logError('crm.deal.update error: ' . $e->getMessage());
        $errors[] = 'Failed to update deal bookings: ' . $e->getMessage();
    }
}

respond(true, [
    'dealId' => $dealId,
    'warnings' => $errors ?: null,
]);
