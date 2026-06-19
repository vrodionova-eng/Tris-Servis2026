# [ШАБЛОН — single-tenant B24 local-app PHP] Новый проект

Скелет single-tenant B24 local-app: PHP-бэк, vanilla JS, файловый store без БД,
защита `<?php exit;?>` без `.htaccess`.

**Tenant-режим зафиксирован:** single-tenant. Multi-tenant (маркет, N порталов одним
деплоем, white-label) — **смена шаблона**, не доработка этого.

---

## КЛОДУ: старт без вопросов

Шаблон работает «из коробки» с дефолтами:
- **Slug** = имя папки проекта в `/data/projects/`
- **Хостинг** = Netangels (zorintest), `https://testzorin.na4u.ru/<slug>/`
- **Скоупы** = `crm user placement` (минимум шаблона)
- **Плейсменты** = `LEFT_MENU` (биндится при установке)
- **Cron** = есть `www/bin/process.php` (если не нужен — не активируется, не мешает)

На команду «стартуем» / «давай начнём» / любой первый запрос — **сразу деплою
`bash deploy-prod.sh`** и отдаю юзеру URL `init.php`. Никаких onboarding-вопросов
(см. правило `rule-default-b24-local-app-php-netangels` в `/data/kb/`).

Параметры собираются **лениво по факту запроса**:
- Юзер просит фичу со `lists.*` / задачами / телефонией → добавляю нужный scope в `init.php`-инструкцию и в карточку local-app
- Юзер просит вкладку в карточке сделки / виджет → раскомментирую плейсмент в `www/api/bind.php` + добавляю scope
- Юзер просит фоновую обработку → активирую `www/bin/process.php` через панель cron
- Юзер говорит «cron не нужен» → удаляю `www/bin/process.php`

---

## СТЕК

- **PHP** (>=7.4, проверено на 8.x). Без composer, без фреймворков
- **Vanilla JS + CSS** во фронте, без bundler'а
- **Файловый store** через `<?php exit;?>`-защищённые `.php` файлы в `data/`. БД не нужна
- **B24 OAuth** (local-app), токены в `data/b24-tokens.php`

См. [README.md](README.md) шаблона для полного описания каркаса.

---

## КАК Я (КЛОД) РАБОТАЮ В ЭТОМ ПРОЕКТЕ

- **Install-flow** уже зашит — двухфазно через `state` + `installFinish` + reload. См. [rule-b24-install-checklist](/data/kb/rule-b24-install-checklist.md)
- **Деплой** — `bash deploy-prod.sh` (в шаблоне готовый, работает «из коробки» с Netangels + slug=имя папки проекта). См. [rule-auto-deploy-no-asking](/data/kb/rule-auto-deploy-no-asking.md)
- **REST-вызовы** — через `$b24 = b24(); $b24->call('method', [...])`. Для CRM сущностей предпочитать `crm.item.*` с `entityTypeId`, не legacy `crm.<entity>.*`. См. [rule-crm-item-universal-api](/data/kb/rule-crm-item-universal-api.md)
- **State** — через `storeRead(FILE)` / `storeWrite(FILE, $data)`. Все state-файлы в `data/`, защищены `<?php exit;?>` префиксом
- **Любые грабли Б24** — сначала `grep` по `/data/kb/INDEX.md`, потом конкретный файл

## Раскладка на сервере — ОДНА папка

Этот шаблон деплоится **одной папкой** в `~/<DOMAIN>/www/<slug>/`. Внутри неё всё: `index.php`, `env.php`, `api/`, `css/`, `js/` и **`data/` тоже здесь** (store защищён `<?php exit;?>`-префиксом в .php-файлах, веб не достаёт). НЕ создавать `~/<DOMAIN>/app-<slug>/` — это устаревший legacy-расклад из других шаблонов, к single-tenant-PHP не относится. `deploy-prod.sh` в этом шаблоне правильный — синхронизировать с ним, не «улучшать» под старые правила.

## Apache, не nginx — следствия для шаблона

Netangels — **Apache 2.4 + PHP-FPM**, не nginx (правило в `/data/kb/` про nginx исправлено, но привычка осталась). Глобально `Options -Indexes` и `DirectoryIndex` без `.php`. Что из этого вытекает и УЖЕ учтено в шаблоне:

- **`.htaccess` не используется by design.** Защита state'а — через `<?php exit;?>`-префикс, портабельно на любой shared без AllowOverride. НЕ добавлять `.htaccess` в `www/`, даже если кажется что «надо для DirectoryIndex».
- **Handler URL в карточке B24 local-app заканчивается на `/index.php`**, не на `/`. `init.php` уже печатает правильную форму с `/index.php` — копировать оттуда. Без этого Apache отдаёт 403 на GET/POST к директории.
- **`template.html`, не `index.html`** — чтобы Apache не отдал статику первым по DirectoryIndex.
- **REFERER-fence в session-gate двусторонний** — пропускает и B24-портал, и self-APP_URL. iframe-reload после `BX24.installFinish()` приходит с self-REFERER, без этого мигнёт 403 между установкой и финальной страницей. См. [b24-install-finish-reload-self-referer](/data/kb/b24-install-finish-reload-self-referer.md).

Полный набор defaults для меня — `/data/kb/_dima-z-include.md` (инжектится автоматически в проектах с `owner: dima.z` в `.portal-meta.json`).
