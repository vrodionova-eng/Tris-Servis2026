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
//
// Бронирование ресурсов заполняется через строковый формат Bitrix24 UI:
//   user|<userId>|<DD.MM.YYYY HH:MM:SS>|<duration_sec>|<serviceName>
// Например: user|57|17.07.2026 09:00:00|10800|Выезд к клиенту

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

/**
 * Загружает карту ресурсов/пользователей из настроек UF-поля resourcebooking.
 * Возвращает [фамилия => id] для type=user и type=resource.
 */
function loadResourceMap(string $fieldName): array
{
    static $cache = [];
    if (isset($cache[$fieldName])) return $cache[$fieldName];

    $map = [];
    try {
        $fields = b24wh('crm.deal.fields', []);
        $settings = $fields[$fieldName]['settings'] ?? [];

        // Все активные пользователи Б24 — ищем по фамилии
        try {
            $users = b24wh('user.get', ['ACTIVE' => true, 'select' => ['ID', 'LAST_NAME', 'NAME']]);
            foreach ((array)$users as $u) {
                $surname = trim((string)($u['LAST_NAME'] ?? ''));
                $userId  = (int)($u['ID'] ?? 0);
                if ($surname !== '' && $userId > 0) {
                    $map[$surname] = ['type' => 'user', 'id' => $userId];
                }
            }
        } catch (Throwable $e) {
            logError('user.get in loadResourceMap: ' . $e->getMessage());
        }

        // Ресурсы календаря (SECTIONS) — ищем по названию ресурса
        $resources = $settings['RESOURCES'] ?? [];
        if (isset($resources['resource']['SECTIONS'])) {
            foreach ((array)$resources['resource']['SECTIONS'] as $section) {
                $name = trim((string)($section['NAME'] ?? ''));
                $id   = (int)($section['ID'] ?? 0);
                if ($id <= 0 || $name === '') continue;
                // Ресурсы добавляем только если фамилия не занята пользователем
                // (например, "Белый Largus" — это ресурс, не сотрудник)
                if (!isset($map[$name])) {
                    $map[$name] = ['type' => 'resource', 'id' => $id];
                }
                // Первое слово ресурса тоже как ключ, если оно не фамилия сотрудника
                $parts = preg_split('/\s+/', $name);
                $first = $parts[0] ?? '';
                if ($first !== '' && !isset($map[$first])) {
                    $map[$first] = ['type' => 'resource', 'id' => $id];
                }
            }
        }
    } catch (Throwable $e) {
        logError('loadResourceMap(' . $fieldName . ') error: ' . $e->getMessage());
    }

    $cache[$fieldName] = $map;
    return $map;
}

function buildBookingValue(string $type, int $id, string $dateB24, int $durationSec = 10800, string $service = 'Выезд к клиенту'): string
{
    $timeFrom = $dateB24 . ' 09:00:00';
    return implode('|', [$type, (string)$id, $timeFrom, (string)$durationSec, $service]);
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

const DURATION_SEC = 10800; // 3 часа по умолчанию
const SERVICE_NAME = 'Выезд к клиенту';

const FUNNEL_FIELDS = [
    'Сервисное обслуживание' => ['serviceTeam', 'partsTeam'],
    'Плановое ТО'            => ['to2Team1', 'to2Team2'],
];

// ── Load resource maps for each booking field ────────────────────────────────
$resourceMaps = [];
try {
    foreach (FIELDS as $key => $fieldName) {
        $resourceMaps[$key] = loadResourceMap($fieldName);
    }
} catch (Throwable $e) {
    logError('Resource map load error: ' . $e->getMessage());
    respond(false, ['error' => 'Failed to load resource maps']);
}

// ── Build deal fields with bookings ─────────────────────────────────────────────
$catConfig = CATEGORIES[$funnel];
$dealFields = [
    'TITLE'        => $name,
    'CATEGORY_ID'  => $catConfig['id'],
    'STAGE_ID'     => $catConfig['stage'],
];

$errors = [];
foreach (FUNNEL_FIELDS[$funnel] as $key) {
    $surnames = (array)($payload[$key] ?? []);
    if (empty($surnames)) continue;

    $map = $resourceMaps[$key] ?? [];
    $values = [];
    foreach ($surnames as $surname) {
        $surname = trim((string)$surname);
        if ($surname === '') continue;
        $found = $map[$surname] ?? null;
        if ($found === null) {
            $errors[] = "Resource not found: $surname";
            continue;
        }
        $values[] = buildBookingValue(
            $found['type'],
            $found['id'],
            $dateB24,
            DURATION_SEC,
            SERVICE_NAME
        );
    }
    if (!empty($values)) {
        $dealFields[FIELDS[$key]] = $values;
    }
}

// ── Create deal ───────────────────────────────────────────────────────────────
$dealId = null;
$result = null;
try {
    $result = b24wh('crm.deal.add', ['fields' => $dealFields, 'params' => ['REGISTER_SONET_EVENT' => 'N']]);
    $dealId = (int)(is_array($result) ? ($result['ID'] ?? $result['id'] ?? 0) : $result);
} catch (Throwable $e) {
    logError('crm.deal.add error: ' . $e->getMessage());
    respond(false, ['error' => 'Failed to create deal: ' . $e->getMessage()]);
}

if ($dealId <= 0) {
    logError('crm.deal.add returned no ID: ' . json_encode($result ?? null, JSON_UNESCAPED_UNICODE));
    respond(false, ['error' => 'Failed to create deal']);
}

respond(true, [
    'dealId'   => $dealId,
    'warnings' => $errors ?: null,
]);
