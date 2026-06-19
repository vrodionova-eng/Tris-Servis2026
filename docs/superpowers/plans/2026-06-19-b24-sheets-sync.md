# B24 → Google Sheets Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cron-скрипт каждые 10 минут читает изменённые сделки из Б24 (4 поля бронирования) и инкрементально обновляет матрицу Google Sheets (строки=даты, колонки=сервисанты, ячейки=кликабельные ссылки на сделки).

**Architecture:** Инкрементальная синхронизация через кеш `deal_cells.php` (deal_id → [{date, tech}]). При каждом запуске: читаем сделки изменённые с `last_sync`, вычисляем diff, обновляем только затронутые ячейки одним `batchUpdate`. Google Sheets авторизация — Service Account JWT, подписанный через `openssl_sign` (без Composer).

**Tech Stack:** PHP 7.4+, Google Sheets API v4 (`spreadsheets/{id}:batchUpdate`), Bitrix24 REST API (`crm.item.list`), cURL, openssl extension

---

## Файловая структура

| Файл | Действие | Ответственность |
|------|----------|----------------|
| `www/api/diag_booking.php` | Создать (временный, удалить после Task 1) | Диагностика формата поля бронирования |
| `www/env.example` | Дополнить | Новые константы: SHEETS_ID, TECH_COLUMNS и др. |
| `www/api/sheets.php` | Создать | GoogleSheets-класс: JWT auth, Sheets API |
| `www/api/sync.php` | Создать | Бизнес-логика: parseBookings, buildRichText, diff |
| `www/bin/process.php` | Дополнить | runJob(): оркестрация синхронизации |

Новые файлы в `data/` (создаются автоматически runtime'ом):
- `last_sync.php`, `deal_cells.php`, `deal_info.php`, `resource_names.php`
- `google_token.php`, `google-sa-key.php` (кладётся вручную)

---

## Task 1: Диагностика формата поля «Бронирование ресурсов»

> Формат ответа API неизвестен заранее. Эта задача выясняет реальную структуру до написания парсера.

**Files:**
- Create: `www/api/diag_booking.php` (удалить после выполнения задачи)

- [ ] **Создать диагностический скрипт**

```php
<?php
// www/api/diag_booking.php — ВРЕМЕННЫЙ. Удалить после Task 1.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/b24.php';

$b24 = b24();

// 1. Смотрим какие методы бронирования доступны
echo "=== crm.item.fields (booking field info) ===\n";
$fields = $b24->call('crm.item.fields', ['entityTypeId' => 2]);
$bookingCodes = [
    'UF_CRM_1750775559215','UF_CRM_1751015039070',
    'UF_CRM_1750920048783','UF_CRM_1750920231839',
];
foreach ($bookingCodes as $code) {
    $f = $fields['result']['fields'][$code] ?? null;
    if ($f) echo "$code → type: {$f['type']}, items: " . json_encode($f['items'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
}

// 2. Берём последние 5 сделок и смотрим значения полей
echo "\n=== crm.item.list (последние 5 сделок) ===\n";
$resp = $b24->call('crm.item.list', [
    'entityTypeId' => 2,
    'select' => array_merge(['ID','TITLE'], $bookingCodes),
    'order'  => ['DATE_MODIFY' => 'DESC'],
]);
foreach (array_slice($resp['result']['items'] ?? [], 0, 5) as $item) {
    $hasAny = false;
    foreach ($bookingCodes as $code) { if (!empty($item[$code])) { $hasAny = true; break; } }
    if (!$hasAny) continue;
    echo "\n--- Сделка #{$item['ID']}: {$item['TITLE']} ---\n";
    foreach ($bookingCodes as $code) {
        if (!empty($item[$code])) {
            echo "$code:\n" . json_encode($item[$code], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
        }
    }
}

// 3. Пробуем получить список ресурсов
echo "\n=== Попытка crm.resourcebooking.resource.list ===\n";
try {
    $res = $b24->call('crm.resourcebooking.resource.list', []);
    echo json_encode($res['result'] ?? $res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "Метод недоступен: " . $e->getMessage() . "\n";
    // Если метод недоступен — пробуем user.list для маппинга по именам
    echo "\n=== user.list (первые 20 пользователей) ===\n";
    $users = $b24->call('user.list', ['select' => ['ID','NAME','LAST_NAME']]);
    foreach (($users['result'] ?? []) as $u) {
        echo "ID {$u['ID']}: {$u['LAST_NAME']} {$u['NAME']}\n";
    }
}
```

- [ ] **Запустить из корня проекта**

```bash
php www/api/diag_booking.php
```

- [ ] **Зафиксировать результат** — смотрим на вывод и отвечаем на вопросы:
  - Какой формат `UF_CRM_...`? Массив объектов с `RESOURCE_ID/DATE_FROM/DATE_TO`? Или другой?
  - Что такое `RESOURCE_ID` — числовой ID ресурса или ID пользователя Б24?
  - Доступен ли метод `crm.resourcebooking.resource.list`?
  - Как называются поля ресурса (NAME? LAST_NAME? другое)?

> ⚠️ Если формат существенно отличается от ожидаемого — скорректировать `parseBookings()` в Task 5 перед написанием.

- [ ] **Удалить диагностический скрипт после изучения результата**

```bash
rm www/api/diag_booking.php
```

---

## Task 2: Константы в env.example

**Files:**
- Modify: `www/env.example`

- [ ] **Дополнить env.example новыми константами** (добавить в конец файла)

```php
// ── Google Sheets ─────────────────────────────────────────────────────
define('SHEETS_ID',      '1jjBjuwvxChxmk0L5_Rk9Sr4it9v47AZCCbaHXEyMhr4');
define('SHEETS_SHEET',   'Лист1');  // Имя вкладки — проверь в таблице!
define('GOOGLE_SA_FILE', DATA_ROOT . '/google-sa-key.php');
define('GOOGLE_TOK_FILE', DATA_ROOT . '/google_token.php');

// ── B24 поля бронирования ─────────────────────────────────────────────
define('B24_BOOKING_FIELDS', [
    'UF_CRM_1750775559215',   // Сервисная бригада (СО)
    'UF_CRM_1751015039070',   // Сервисная бригада для замены (СО)
    'UF_CRM_1750920048783',   // Сервисная бригада ТО-1
    'UF_CRM_1750920231839',   // Сервисная бригада ТО-2
]);

// ── Сервисанты: фамилия → буква колонки ──────────────────────────────
define('TECH_COLUMNS', [
    'Тусюк'    => 'B',
    'Кузовко'  => 'C',
    'Муха'     => 'D',
    'Козлянко' => 'E',
    'Юрченков' => 'F',
]);

// ── State-файлы синхронизации ─────────────────────────────────────────
define('LAST_SYNC_FILE',     DATA_ROOT . '/last_sync.php');
define('DEAL_CELLS_FILE',    DATA_ROOT . '/deal_cells.php');
define('DEAL_INFO_FILE',     DATA_ROOT . '/deal_info.php');
define('RESOURCE_NAMES_FILE', DATA_ROOT . '/resource_names.php');
```

- [ ] **Продублировать те же константы в `www/env.php`** на сервере (env.php не в git — добавляем вручную)

- [ ] **Commit**

```bash
git add www/env.example
git commit -m "feat: add Google Sheets and B24 booking field constants to env.example"
```

---

## Task 3: GoogleSheets — авторизация (JWT + getToken)

**Files:**
- Create: `www/api/sheets.php`

- [ ] **Создать `www/api/sheets.php` с классом GoogleSheets и JWT-авторизацией**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/lib.php';

/**
 * Google Sheets API v4 — Service Account авторизация + операции с листом.
 * Без Composer: JWT подписывается через openssl_sign (PHP built-in).
 *
 * Перед использованием в data/ должен лежать google-sa-key.php —
 * JSON-ключ Service Account, сохранённый через storeWrite().
 * Инструкция: docs/superpowers/plans/2026-06-19-b24-sheets-sync.md Task 8.
 */
final class GoogleSheets
{
    private const SCOPE     = 'https://www.googleapis.com/auth/spreadsheets';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE  = 'https://sheets.googleapis.com/v4/spreadsheets/';

    // ── Auth ─────────────────────────────────────────────────────────────

    public function getToken(): string
    {
        $cached = storeRead(GOOGLE_TOK_FILE);
        if (is_array($cached) && (int)($cached['expires_at'] ?? 0) > time() + 60) {
            return (string)$cached['access_token'];
        }

        $key = storeRead(GOOGLE_SA_FILE);
        if (!is_array($key) || empty($key['private_key']) || empty($key['client_email'])) {
            throw new RuntimeException('Google SA key не загружен. Положи google-sa-key.php в data/.');
        }

        $jwt  = $this->buildJWT($key);
        $resp = httpJson('POST', self::TOKEN_URL, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);

        $token = (string)($resp['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Google token: пустой ответ: ' . json_encode($resp));
        }

        storeWrite(GOOGLE_TOK_FILE, [
            'access_token' => $token,
            'expires_at'   => time() + (int)($resp['expires_in'] ?? 3600) - 60,
        ]);
        return $token;
    }

    private function buildJWT(array $key): string
    {
        $b64  = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now  = time();
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body = $b64(json_encode([
            'iss'   => $key['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $sig = '';
        if (!openssl_sign("$head.$body", $sig, $key['private_key'], 'SHA256')) {
            throw new RuntimeException('Google JWT: openssl_sign не удался');
        }
        return "$head.$body." . $b64($sig);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function authHeader(): string
    {
        return 'Authorization: Bearer ' . $this->getToken();
    }

    /** "B5" → [col_0indexed, row_1indexed] */
    private function parseRef(string $ref): array
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
            throw new \InvalidArgumentException("Неверный ref ячейки: $ref");
        }
        $col = 0;
        foreach (str_split($m[1]) as $c) {
            $col = $col * 26 + (ord($c) - ord('A'));
        }
        return [(int)$col, (int)$m[2]];
    }

    /** "19.06.2026" → timestamp */
    private function parseDateStr(string $date): int
    {
        [$d, $m, $y] = explode('.', $date);
        return (int)mktime(0, 0, 0, (int)$m, (int)$d, (int)$y);
    }
}
```

- [ ] **Проверить что PHP openssl доступен**

```bash
php -r "echo openssl_sign('test', \$s, openssl_pkey_new(), 'SHA256') ? 'OK' : 'FAIL';"
```

Ожидаемый вывод: `OK`

- [ ] **Commit**

```bash
git add www/api/sheets.php
git commit -m "feat: add GoogleSheets class with JWT Service Account auth"
```

---

## Task 4: GoogleSheets — операции с листом

**Files:**
- Modify: `www/api/sheets.php` (добавить методы к классу)

- [ ] **Добавить метод `readColumnA()` перед закрывающей `}` класса**

```php
    // ── Sheet operations ─────────────────────────────────────────────────

    /**
     * Читает колонку A целиком, возвращает map: "19.06.2026" => row_number (1-based).
     * Строка-заголовок ("Даты") пропускается.
     */
    public function readColumnA(): array
    {
        $range = urlencode(SHEETS_SHEET . '!A:A');
        $resp  = httpJson('GET', self::API_BASE . SHEETS_ID . "/values/$range", [
            'headers' => [$this->authHeader()],
        ]);
        $map = [];
        foreach (($resp['values'] ?? []) as $i => $row) {
            $val = trim((string)($row[0] ?? ''));
            if ($val !== '' && $val !== 'Даты') {
                $map[$val] = $i + 1; // 1-based row number
            }
        }
        return $map;
    }

    /**
     * Вставляет новую строку для даты в нужную позицию (по хронологии),
     * пишет дату в ячейку A{row}, обновляет $dateToRow.
     * Возвращает номер вставленной строки.
     */
    public function insertDateRow(string $date, array &$dateToRow): int
    {
        $dateTs = $this->parseDateStr($date);

        // Находим строку после которой вставлять (ищем последнюю дату < текущей)
        $insertAfter = 1; // по умолчанию — после заголовка
        foreach ($dateToRow as $existingDate => $row) {
            if ($this->parseDateStr($existingDate) < $dateTs) {
                $insertAfter = max($insertAfter, $row);
            }
        }
        $newRow = $insertAfter + 1;

        // Сдвигаем все строки >= newRow в кеше
        foreach ($dateToRow as $d => $r) {
            if ($r >= $newRow) $dateToRow[$d] = $r + 1;
        }

        // Вставляем пустую строку (sheetId=0 — первый лист)
        $insertReq = ['requests' => [['insertDimension' => [
            'range' => [
                'sheetId'    => 0,
                'dimension'  => 'ROWS',
                'startIndex' => $newRow - 1, // 0-based
                'endIndex'   => $newRow,
            ],
            'inheritFromBefore' => false,
        ]]]];
        httpJson('POST', self::API_BASE . SHEETS_ID . ':batchUpdate', [
            'headers' => [$this->authHeader(), 'Content-Type: application/json'],
            'body'    => json_encode($insertReq),
        ]);

        // Пишем дату в A{newRow}
        $range = urlencode(SHEETS_SHEET . "!A$newRow");
        httpJson('PUT', self::API_BASE . SHEETS_ID . "/values/$range?valueInputOption=USER_ENTERED", [
            'headers' => [$this->authHeader(), 'Content-Type: application/json'],
            'body'    => json_encode(['values' => [[$date]]]),
        ]);

        $dateToRow[$date] = $newRow;
        return $newRow;
    }

    /**
     * Batch-обновление ячеек: один запрос к Sheets API для всех изменений.
     *
     * $updates = [
     *   ['cellRef' => 'B5', 'richText' => ['text' => '...', 'runs' => [...]]],
     *   ...
     * ]
     * Если richText['text'] пустой — ячейка очищается.
     */
    public function batchUpdate(array $updates): void
    {
        if (empty($updates)) return;

        $requests = [];
        foreach ($updates as $u) {
            [$col, $row] = $this->parseRef($u['cellRef']);
            $rt = $u['richText'];

            if ($rt['text'] === '') {
                // Очистить ячейку
                $cellVal = ['userEnteredValue' => []];
                $fields  = 'userEnteredValue,textFormatRuns';
            } else {
                $cellVal = [
                    'userEnteredValue' => ['stringValue' => $rt['text']],
                    'textFormatRuns'   => $rt['runs'],
                    'userEnteredFormat' => ['wrapStrategy' => 'WRAP'],
                ];
                $fields = 'userEnteredValue,textFormatRuns,userEnteredFormat.wrapStrategy';
            }

            $requests[] = ['updateCells' => [
                'rows'   => [['values' => [$cellVal]]],
                'fields' => $fields,
                'start'  => ['sheetId' => 0, 'rowIndex' => $row - 1, 'columnIndex' => $col],
            ]];
        }

        httpJson('POST', self::API_BASE . SHEETS_ID . ':batchUpdate', [
            'headers' => [$this->authHeader(), 'Content-Type: application/json'],
            'body'    => json_encode(['requests' => $requests]),
        ]);
    }
```

- [ ] **Commit**

```bash
git add www/api/sheets.php
git commit -m "feat: add readColumnA, insertDateRow, batchUpdate to GoogleSheets"
```

---

## Task 5: sync.php — кеш ресурсов + parseBookings

**Files:**
- Create: `www/api/sync.php`

> ⚠️ `loadResourceNames()` написан под ожидаемый ответ `crm.resourcebooking.resource.list`. Если Task 1 показал другой метод/формат — скорректируй функцию перед написанием.

- [ ] **Создать `www/api/sync.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/b24.php';

/**
 * Бизнес-логика синхронизации Б24 → Google Sheets.
 * Вызывается из process.php::runJob().
 */

/**
 * Возвращает map: resource_id => фамилия (первое слово полного имени).
 * Кешируется в data/resource_names.php.
 * Если метод crm.resourcebooking.resource.list недоступен — пробует user.list.
 */
function loadResourceNames(): array
{
    $cached = storeRead(RESOURCE_NAMES_FILE);
    if (is_array($cached) && !empty($cached)) return $cached;

    $b24   = b24();
    $names = [];

    try {
        $resp  = $b24->call('crm.resourcebooking.resource.list', ['select' => ['ID', 'NAME']]);
        $items = $resp['result']['resources'] ?? $resp['result'] ?? [];
        foreach ($items as $item) {
            $id   = (string)($item['ID'] ?? $item['id'] ?? '');
            $name = (string)($item['NAME'] ?? $item['name'] ?? '');
            if ($id !== '' && $name !== '') {
                $names[$id] = mb_strstr($name, ' ', true) ?: $name; // первое слово
            }
        }
    } catch (Throwable $e) {
        // Fallback: маппинг по пользователям Б24
        $resp  = $b24->call('user.list', ['select' => ['ID', 'NAME', 'LAST_NAME']]);
        foreach (($resp['result'] ?? []) as $u) {
            $id      = (string)($u['ID'] ?? '');
            $surname = trim((string)($u['LAST_NAME'] ?? ''));
            if ($id !== '' && $surname !== '') {
                $names[$id] = $surname;
            }
        }
    }

    if (!empty($names)) storeWrite(RESOURCE_NAMES_FILE, $names);
    return $names;
}

/**
 * Разбирает поля бронирования одной сделки.
 * Возвращает массив [{date: "19.06.2026", tech: "Тусюк"}, ...].
 * Одна запись = один день × один сервисант.
 * Многодневные бронирования разворачиваются в отдельные записи.
 *
 * @param array $deal       Запись из crm.item.list (содержит UF_CRM_* поля)
 * @param array $resNames   map resource_id => фамилия
 */
function parseBookings(array $deal, array $resNames): array
{
    $cells    = [];
    $techCols = TECH_COLUMNS;

    foreach (B24_BOOKING_FIELDS as $field) {
        $bookings = $deal[$field] ?? [];
        if (!is_array($bookings) || empty($bookings)) continue;

        // Поле может вернуть одиночный объект или массив — нормализуем
        if (isset($bookings['RESOURCE_ID'])) $bookings = [$bookings];

        foreach ($bookings as $booking) {
            if (!is_array($booking)) continue;

            $resourceId = (string)($booking['RESOURCE_ID'] ?? '');
            $dateFrom   = (string)($booking['DATE_FROM'] ?? '');
            $dateTo     = (string)($booking['DATE_TO']   ?? $dateFrom);

            if ($resourceId === '' || $dateFrom === '') continue;

            $surname = $resNames[$resourceId] ?? '';
            if ($surname === '' || !isset($techCols[$surname])) continue;

            $tsFrom = strtotime($dateFrom);
            $tsTo   = strtotime($dateTo);
            if ($tsFrom === false || $tsTo === false || $tsFrom > $tsTo) continue;

            // Одна запись на каждый день диапазона
            for ($ts = $tsFrom; $ts <= $tsTo; $ts += 86400) {
                $dateStr = date('d.m.Y', $ts);
                $key     = $dateStr . '|' . $surname;
                if (!isset($cells[$key])) {
                    $cells[$key] = ['date' => $dateStr, 'tech' => $surname];
                }
            }
        }
    }

    return array_values($cells);
}
```

- [ ] **Commit**

```bash
git add www/api/sync.php
git commit -m "feat: add sync.php with loadResourceNames and parseBookings"
```

---

## Task 6: sync.php — buildRichText + computeDiff

**Files:**
- Modify: `www/api/sync.php` (добавить функции в конец файла)

- [ ] **Добавить в конец `www/api/sync.php`**

```php
/**
 * Строит richText для ячейки из списка deal_id.
 * Возвращает ['text' => '...', 'runs' => [...]] для GoogleSheets::batchUpdate().
 * Пустой массив $dealIds → ['text' => '', 'runs' => []] (очистка ячейки).
 *
 * @param string[] $dealIds   Список ID сделок в ячейке
 * @param array    $dealInfo  map deal_id => ['title' => ..., 'url' => ...]
 */
function buildRichText(array $dealIds, array $dealInfo): array
{
    $parts = [];
    $runs  = [];
    $pos   = 0;

    foreach ($dealIds as $id) {
        $info = $dealInfo[(string)$id] ?? null;
        if (!$info) continue;
        $title = (string)($info['title'] ?? "Сделка #$id");
        $url   = (string)($info['url']   ?? '');
        $runs[] = ['startIndex' => $pos, 'format' => ['link' => ['uri' => $url]]];
        $parts[] = $title;
        $pos += mb_strlen($title, 'UTF-8') + 1; // +1 для \n между записями
    }

    return [
        'text' => implode("\n", $parts),
        'runs' => $runs,
    ];
}

/**
 * Возвращает список ячеек, затронутых изменением сделки.
 *
 * @param array[] $oldCells  [{date, tech}] — что было в кеше
 * @param array[] $newCells  [{date, tech}] — что стало
 */
function computeAffected(array $oldCells, array $newCells): array
{
    $toKey = fn(array $c) => $c['date'] . '|' . $c['tech'];
    $seen  = [];
    $result = [];
    foreach (array_merge($oldCells, $newCells) as $cell) {
        $k = $toKey($cell);
        if (!isset($seen[$k])) {
            $seen[$k]  = true;
            $result[]  = $cell;
        }
    }
    return $result;
}

/**
 * Возвращает все deal_id которые должны быть в ячейке {date, tech}
 * на основе актуального deal_cells (уже обновлённого для текущей сделки).
 *
 * @param string $date
 * @param string $tech
 * @param array  $dealCells  map deal_id => [{date, tech}]
 */
function dealsInCell(string $date, string $tech, array $dealCells): array
{
    $ids = [];
    foreach ($dealCells as $dealId => $cells) {
        foreach ($cells as $cell) {
            if ($cell['date'] === $date && $cell['tech'] === $tech) {
                $ids[] = (string)$dealId;
                break;
            }
        }
    }
    sort($ids); // стабильный порядок
    return $ids;
}
```

- [ ] **Commit**

```bash
git add www/api/sync.php
git commit -m "feat: add buildRichText, computeAffected, dealsInCell to sync.php"
```

---

## Task 7: process.php — runJob()

**Files:**
- Modify: `www/bin/process.php`

- [ ] **Добавить require в начало `process.php`** (после существующих require)

Найди блок require в `www/bin/process.php` (строки ~21-23):
```php
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../api/b24.php';
require_once __DIR__ . '/../api/lib.php';
```

Добавить после них:
```php
require_once __DIR__ . '/../api/sheets.php';
require_once __DIR__ . '/../api/sync.php';
```

- [ ] **Заменить заглушку `runJob()` в `process.php`**

Найди и замени функцию `runJob()` (сейчас содержит `logline('NOOP: ...')`):

```php
function runJob(): void
{
    // ── 1. Читаем last_sync и текущую карту дат листа ────────────────────
    $lastSync  = (int)(storeRead(LAST_SYNC_FILE)['ts'] ?? 0);
    $dealCells = storeRead(DEAL_CELLS_FILE) ?? [];  // deal_id => [{date,tech}]
    $dealInfo  = storeRead(DEAL_INFO_FILE)  ?? [];  // deal_id => {title, url}

    $sheets   = new GoogleSheets();
    $dateToRow = $sheets->readColumnA(); // "19.06.2026" => row_number
    logline('Дат в листе: ' . count($dateToRow) . ', last_sync: ' . ($lastSync ? date('c', $lastSync) : 'never'));

    // ── 2. Загружаем ресурсы (с кешем) ───────────────────────────────────
    $resNames = loadResourceNames();
    logline('Ресурсов в кеше: ' . count($resNames));

    // ── 3. Берём изменившиеся сделки из Б24 ──────────────────────────────
    $select = array_merge(['ID', 'TITLE'], B24_BOOKING_FIELDS);
    $b24    = b24();
    $resp   = $b24->call('crm.item.list', [
        'entityTypeId' => 2,
        'filter'       => $lastSync ? ['>=DATE_MODIFY' => date('c', $lastSync)] : [],
        'select'       => $select,
        'order'        => ['DATE_MODIFY' => 'ASC'],
    ]);
    $deals = $resp['result']['items'] ?? [];
    logline('Изменившихся сделок: ' . count($deals));

    if (empty($deals)) {
        storeWrite(LAST_SYNC_FILE, ['ts' => time()]);
        return;
    }

    // ── 4. Обрабатываем каждую сделку ────────────────────────────────────
    $batchUpdates = [];
    $portal       = b24Portal() ?? '';

    foreach ($deals as $deal) {
        $dealId = (string)($deal['ID'] ?? '');
        $title  = (string)($deal['TITLE'] ?? "Сделка #$dealId");
        $url    = "https://$portal/crm/deal/details/$dealId/";

        // Пропускаем если все booking-поля пустые
        $hasBooking = false;
        foreach (B24_BOOKING_FIELDS as $f) {
            if (!empty($deal[$f])) { $hasBooking = true; break; }
        }

        $oldCells = $dealCells[$dealId] ?? [];

        if (!$hasBooking) {
            // Поля очищены — удаляем сделку из всех ячеек
            $newCells = [];
        } else {
            $newCells = parseBookings($deal, $resNames);
        }

        // Обновляем кеши
        $dealCells[$dealId] = $newCells;
        $dealInfo[$dealId]  = ['title' => $title, 'url' => $url];

        // Определяем затронутые ячейки
        $affected = computeAffected($oldCells, $newCells);

        foreach ($affected as $cell) {
            ['date' => $date, 'tech' => $tech] = $cell;

            // Добавляем строку если даты ещё нет в листе
            if (!isset($dateToRow[$date])) {
                logline("Добавляю строку для даты $date");
                $sheets->insertDateRow($date, $dateToRow);
            }

            $col     = TECH_COLUMNS[$tech] ?? null;
            if (!$col) continue;

            $cellRef  = $col . $dateToRow[$date];
            $allDeals = dealsInCell($date, $tech, $dealCells);
            $batchUpdates[$cellRef] = [
                'cellRef'  => $cellRef,
                'richText' => buildRichText($allDeals, $dealInfo),
            ];
        }

        logline("Сделка #$dealId «$title»: old=" . count($oldCells) . " new=" . count($newCells) . " affected=" . count($affected));
    }

    // ── 5. Один batchUpdate для всех изменений ────────────────────────────
    if (!empty($batchUpdates)) {
        logline('Обновляю ячеек: ' . count($batchUpdates));
        $sheets->batchUpdate(array_values($batchUpdates));
    }

    // ── 6. Сохраняем состояние ────────────────────────────────────────────
    storeWrite(LAST_SYNC_FILE,  ['ts' => time()]);
    storeWrite(DEAL_CELLS_FILE, $dealCells);
    storeWrite(DEAL_INFO_FILE,  $dealInfo);
    logline('Синхронизация завершена');
}
```

- [ ] **Commit**

```bash
git add www/bin/process.php
git commit -m "feat: implement runJob() — B24 to Google Sheets incremental sync"
```

---

## Task 8: Настройка Google Service Account (ручная, одноразовая)

> Это не код — это инструкция по настройке внешнего сервиса.

- [ ] **Создать проект в Google Cloud Console**
  1. Открой [console.cloud.google.com](https://console.cloud.google.com)
  2. Создай новый проект (или выбери существующий)
  3. **APIs & Services → Enable APIs** → найди и включи **Google Sheets API**

- [ ] **Создать Service Account**
  1. **IAM & Admin → Service Accounts → Create Service Account**
  2. Имя: `tris-servis-sheets` → Create
  3. Роль: пропустить (нам не нужна роль в GCP, только доступ к Sheets)
  4. **Keys → Add Key → Create new key → JSON** → скачай файл

- [ ] **Расшарить таблицу на Service Account**
  1. Открой скачанный JSON — найди поле `client_email` (выглядит как `tris-servis-sheets@project-xxx.iam.gserviceaccount.com`)
  2. Открой Google Sheets [таблицу](https://docs.google.com/spreadsheets/d/1jjBjuwvxChxmk0L5_Rk9Sr4it9v47AZCCbaHXEyMhr4)
  3. **Поделиться → добавить email Service Account → Редактор**

- [ ] **Сохранить ключ на сервере**

  В терминале VPS или из PHP-консоли создай файл `data/google-sa-key.php`:
  ```php
  // На VPS, в папке с установленным приложением:
  php -r "
  require_once 'www/api/store.php';
  require_once 'www/env.php';
  \$key = json_decode(file_get_contents('/tmp/sa-key.json'), true);
  storeWrite(GOOGLE_SA_FILE, \$key);
  echo 'OK: ' . \$key['client_email'] . PHP_EOL;
  "
  ```
  (Предварительно загрузи JSON-файл на сервер через SFTP в `/tmp/sa-key.json`)

- [ ] **Проверить авторизацию**

  ```php
  // Временная проверка в PHP CLI:
  php -r "
  require_once 'www/env.php';
  require_once 'www/api/store.php';
  require_once 'www/api/lib.php';
  require_once 'www/api/sheets.php';
  \$s = new GoogleSheets();
  echo \$s->getToken() ? 'Token OK' : 'FAIL';
  "
  ```

- [ ] **Проверить чтение листа**

  ```php
  php -r "
  require_once 'www/env.php';
  require_once 'www/api/store.php';
  require_once 'www/api/lib.php';
  require_once 'www/api/sheets.php';
  \$s = new GoogleSheets();
  print_r(\$s->readColumnA());
  "
  ```

  Ожидаемый вывод: `Array ( )` (лист пустой, только заголовок пропускается).

- [ ] **Проверить имя вкладки**

  Если `readColumnA()` бросает ошибку про диапазон — проверь имя вкладки в таблице. Обнови константу `SHEETS_SHEET` в `env.php` и `env.example`.

---

## Task 9: Первый прогон cron вручную

- [ ] **Запустить process.php вручную**

```bash
php www/bin/process.php
```

- [ ] **Проверить лог**

```bash
cat www/data/cron-logs/$(date +%Y-%m-%d).log
```

Ожидаемый вывод:
```
[...] === start ===
[...] Дат в листе: 0, last_sync: never
[...] Ресурсов в кеше: 5
[...] Изменившихся сделок: N
[...] Сделка #XXX «...»: old=0 new=M affected=M
[...] Обновляю ячеек: K
[...] Синхронизация завершена
[...] === end (X.XXs) ===
```

- [ ] **Проверить таблицу** — открой [Google Sheets](https://docs.google.com/spreadsheets/d/1jjBjuwvxChxmk0L5_Rk9Sr4it9v47AZCCbaHXEyMhr4) и убедись что появились строки с датами и ссылками на сделки

- [ ] **Настроить cron на VPS**

```bash
crontab -e
```

Добавить строку:
```
*/10 * * * * php /путь/до/www/bin/process.php
```

(Замени `/путь/до/` на реальный путь — узнай через `pwd` в папке проекта)

---

## Если что-то пошло не так

**`Google SA key не загружен`** → Task 8 не выполнен, `data/google-sa-key.php` отсутствует

**`openssl_sign не удался`** → PHP собран без openssl или ключ повреждён

**`HTTP 403` от Sheets API** → таблица не расшарена на email Service Account (Task 8, шаг 2)

**`HTTP 404` от Sheets API** → неверный `SHEETS_SHEET` (имя вкладки) или `SHEETS_ID`

**Поля бронирования все пустые** → RESOURCE_ID не совпадает с ключами в `resource_names.php` — запусти диагностику из Task 1 повторно и сравни форматы

**`Нет B24-токенов`** → приложение не установлено в Б24, откройте его из портала
