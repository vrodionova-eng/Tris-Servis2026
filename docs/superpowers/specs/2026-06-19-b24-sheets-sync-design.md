# Дизайн: Синхронизация Битрикс24 → Google Sheets («Плановый график выездов Сервис 2026»)

**Дата:** 2026-06-19  
**Статус:** Одобрен  

---

## 1. Цель и ограничения

Односторонняя синхронизация (Б24 → Google Sheets). Матрица в Google Sheets:
- Строки (колонка A) — календарные даты
- Столбцы B–F — сервисанты: Тусюк, Кузовко, Муха, Козлянко, Юрченков Влад
- Ячейки — кликабельные гиперссылки на сделки (несколько сделок в ячейке через `\n`)

**Что НЕ входит в скоуп:**
- Обратная синхронизация Sheets → Б24
- Ручное редактирование матрицы из приложения

---

## 2. Стек и среда

- PHP без Composer, Vanilla JS (существующий шаблон)
- Деплой: VPS (Ubuntu) + GitHub Actions по SSH
- B24: existing local-app OAuth (single-tenant), токены в `data/b24-tokens.php`
- Google Sheets API: Service Account (server-to-server, JWT через `openssl_sign`)

---

## 3. Архитектура

```
Cron VPS (каждые 10 мин)
  └─ www/bin/process.php  [runJob()]
       ├─ B24 (существующий класс)
       │    └─ crm.item.list — изменённые сделки
       ├─ www/api/sync.php  — логика diff + разбор полей бронирования
       └─ www/api/sheets.php — GoogleSheets-класс (auth + batchUpdate)
```

**State-файлы в `data/` (все защищены `<?php exit;?>`):**

| Файл | Содержимое |
|------|-----------|
| `last_sync.php` | UNIX-timestamp последнего успешного запуска |
| `deal_cells.php` | `{deal_id: [{date, tech}]}` — кеш позиций сделок в матрице |
| `deal_info.php` | `{deal_id: {title, url}}` — название и URL каждой сделки (нужны при ребилде ячейки) |
| `resource_names.php` | `{resource_id: "Тусюк"}` — кеш фамилий ресурсов |
| `google_token.php` | Кешированный Google access token (TTL 1ч) |
| `google-sa-key.php` | JSON-ключ Service Account (в git не попадает) |

---

## 4. B24 поля бронирования

| Поле | API-код | Воронка |
|------|---------|---------|
| Сервисная бригада | `UF_CRM_1750775559215` | Сервисное обслуживание |
| Сервисная бригада (замена запчастей) | `UF_CRM_1751015039070` | Сервисное обслуживание |
| Сервисная бригада ТО-1 | `UF_CRM_1750920048783` | Плановое ТО |
| Сервисная бригада ТО-2 | `UF_CRM_1750920231839` | Плановое ТО |

Тип поля — «Бронирование ресурсов». Ожидаемый формат из API:
```json
[{"RESOURCE_ID":"5","DATE_FROM":"2026-06-19T00:00:00+03:00","DATE_TO":"2026-06-21T00:00:00+03:00"}]
```
Длительность в днях = `(strtotime(DATE_TO) - strtotime(DATE_FROM)) / 86400 + 1`.  
Одна сделка с длительностью 3 дня → 3 записи в `deal_cells`: по одной на каждый день.

Имена ресурсов: кешируются через `crm.resourcebooking.resource.list` в `data/resource_names.php`. Фамилия = первое слово полного имени.

**URL сделки для гиперссылки:**
```
https://{b24Portal()}/crm/deal/details/{ID}/
```

---

## 5. Настройки в `env.php`

```php
define('SHEETS_ID',        '1jjBjuwvxChxmk0L5_Rk9Sr4it9v47AZCCbaHXEyMhr4');
define('SHEETS_SHEET',     'Лист1');
define('GOOGLE_SA_FILE',   DATA_ROOT . '/google-sa-key.php');
define('GOOGLE_TOK_FILE',  DATA_ROOT . '/google_token.php');

define('B24_BOOKING_FIELDS', [
    'UF_CRM_1750775559215',
    'UF_CRM_1751015039070',
    'UF_CRM_1750920048783',
    'UF_CRM_1750920231839',
]);

define('TECH_COLUMNS', [
    'Тусюк'    => 'B',
    'Кузовко'  => 'C',
    'Муха'     => 'D',
    'Козлянко' => 'E',
    'Юрченков' => 'F',
]);
```

---

## 6. Алгоритм инкрементальной синхронизации

```
1. last_sync   = storeRead(last_sync.php) ?? 0
2. date_to_row = sheets->readColumnA()    // читаем колонку A один раз: {"19.06.2026": 2, ...}
3. deals = b24->call('crm.item.list', filter: DATE_MODIFY >= last_sync, select: [ID,TITLE + 4 поля])
4. Если deals пустой → обновляем last_sync, выходим
5. batch_updates = []   // накапливаем все изменения ячеек для одного batchUpdate
6. Для каждой сделки:
     a. Пропускаем, если все 4 booking-поля пустые
     b. new_cells = parse_bookings(deal)       // [{date, tech}] × все дни × все поля
     c. old_cells = deal_cells[deal.ID] ?? []
     d. affected  = union(old_cells, new_cells)
     e. deal_cells[deal.ID] = new_cells        // обновляем кеш позиций
     f. deal_info[deal.ID]  = {title, url}    // обновляем кеш мета-данных
     g. Для каждой затронутой ячейки {date, tech}:
          Если даты нет в date_to_row:
            row = sheets->insertDateRow(date, date_to_row)  // insertDimension + запись даты
            date_to_row[date] = row
          all_deals = [id | {date,tech} in deal_cells[id]]
          cell_ref  = tech_to_col(tech) + date_to_row[date]  // напр. "B15"
          batch_updates[] = {cell_ref, buildRichText(all_deals, deal_info)}
7. sheets->batchUpdate(batch_updates)   // один запрос к Sheets API для всех изменений
8. storeWrite(last_sync.php, time())
9. storeWrite(deal_cells.php, deal_cells)
10. storeWrite(deal_info.php, deal_info)
```

**Формат ячейки с несколькими сделками (`richTextValue`):**
```json
{
  "text": "ТО Газпром #1042\nСервис Роснефть #1051",
  "textFormatRuns": [
    {"startIndex": 0,  "format": {"link": {"uri": "https://tris.bitrix24.by/crm/deal/details/1042/"}}},
    {"startIndex": 17, "format": {"link": {"uri": "https://tris.bitrix24.by/crm/deal/details/1051/"}}}
  ]
}
```

---

## 7. Google Sheets: Service Account без Composer

```
google-sa-key.php → private_key + client_email
  → buildJWT() → openssl_sign(RS256)
  → POST https://oauth2.googleapis.com/token
  → access_token (кешируем на 55 мин в google_token.php)
  → Authorization: Bearer {token}
  → POST https://sheets.googleapis.com/v4/spreadsheets/{id}:batchUpdate
       (не /values:batchUpdate — тот только для plain text; richTextValue требует /:batchUpdate с CellData)
```

Реализация в `www/api/sheets.php`:
- `class GoogleSheets` с методами `getToken()`, `updateCell()`, `insertRow()`
- JWT-подпись через стандартное PHP-расширение `openssl` (есть на PHP 7.4+)
- Все HTTP-вызовы через существующую функцию `httpJson()` из `lib.php`

---

## 8. Автодобавление строк дат

Если в матрице нет строки для даты из бронирования:
1. Найти позицию вставки (даты в колонке A идут по порядку)
2. `sheets->insertRow(position)` — `insertDimension` API
3. Записать дату в ячейку A{position}
4. Продолжить запись сделки в новую строку

Формат дат в колонке A: `ДД.ММ.ГГГГ` (как в существующей шапке).

---

## 9. GitHub Actions + VPS

**`.github/workflows/deploy.yml`:**
```yaml
name: Deploy
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Deploy via SSH
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.VPS_HOST }}
          username: ${{ secrets.VPS_USER }}
          key: ${{ secrets.VPS_SSH_KEY }}
          script: |
            cd ~/Tris-Servis2026
            git pull origin main
```

**Одноразовая настройка VPS:**
1. `git clone <repo> ~/Tris-Servis2026`
2. Настроить `www/env.php` (через init.php или вручную)
3. Положить `data/google-sa-key.php` вручную (не в git)
4. Добавить cron: `*/10 * * * * php ~/Tris-Servis2026/www/bin/process.php`
   (логи пишутся в `data/cron-logs/` через встроенный logline() — stdout не нужен)
5. В GitHub Secrets: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`

**SSH-ключ для GitHub Actions:**
```bash
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/github_deploy
# публичный → ~/.ssh/authorized_keys на VPS
# приватный → GitHub Secrets: VPS_SSH_KEY
```

---

## 10. Что нужно сделать до старта разработки

- [ ] Подтвердить точный формат ответа API поля «Бронирование ресурсов» (тест-вызов на реальной сделке)
- [ ] Убедиться что PHP-расширение `openssl` включено на VPS (`php -m | grep openssl`)
- [ ] Создать Service Account в Google Cloud Console, расшарить таблицу на email SA
- [ ] Проверить имя вкладки в таблице (пока принято `Лист1`)
- [ ] Подготовить GitHub-репозиторий для проекта

---

## 11. Файлы к созданию/изменению

| Файл | Действие |
|------|---------|
| `www/api/sheets.php` | Создать: GoogleSheets-класс (getToken, readColumnA, insertDateRow, batchUpdate) |
| `www/api/sync.php` | Создать: parse_bookings(), buildRichText(deals, deal_info), логика diff |
| `www/bin/process.php` | Дописать: `runJob()` |
| `www/env.example` | Дополнить: SHEETS_ID, TECH_COLUMNS и др. |
| `.github/workflows/deploy.yml` | Создать |
| `.gitignore` | Создать/дополнить: data/, env.php |
