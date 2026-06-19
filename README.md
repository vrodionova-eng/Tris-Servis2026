# `_b24-single-php` — шаблон single-tenant B24 local-app на PHP

Канонический скелет для нового приложения **«один деплой = один портал»**.
PHP-бэкенд, vanilla JS-фронт, файловый store без БД, защита `<?php exit;?>` без `.htaccess`.

Подходит для in-house инструментов, заказных интеграций, одиночных установок.
Для маркета / N-портального деплоя — другой шаблон ([multi-tenant](https://example.invalid)
не сделан, как канон используй `/data/projects/zorintest-calendarplan-node/`).

## Что внутри

```
_b24-single-php/
├── README.md                 ← этот файл
├── CLAUDE.md                 ← плейсхолдер онбординга нового проекта
├── .portal-meta.json         ← tenancy=single, owner=dima.z
├── docs/
│   └── install-flow-diagram.md  ← как ходят POST'ы при установке
└── www/                      ← всё, что копируется в DocumentRoot хостинга
    ├── env.example           ← шаблон env.php
    ├── init.php              ← одноразовый bootstrap нового хостинга
    ├── index.php             ← главный handler (install POST + runtime)
    ├── template.html         ← UI (рендерится через index.php, не Apache)
    ├── api/
    │   ├── store.php         ← <?php exit;?> защита, storeRead/storeWrite
    │   ├── b24.php           ← B24 class — OAuth, refresh, REST call/batch
    │   ├── session.php       ← admin-only gate через user.admin
    │   ├── install.php       ← alternative install handler (если /index.php занят чем-то)
    │   ├── lib.php           ← settings helpers + httpJson обёртка над cURL
    │   └── bind.php          ← placement.bind утилита, идемпотентная
    ├── bin/
    │   └── process.php       ← CLI cron-воркер (заглушка)
    ├── css/style.css         ← минимальные стили
    └── js/app.js             ← точка входа фронта, BX24 init
```

## Что закрывает «из коробки»

Каждый из этих пунктов взят из живых грабель, см. ссылки на заметки:

| Фаза | Где зашито в коде |
|------|-------------------|
| Save-tokens-gate (`INSTALL=Y` / `ONAPPINSTALL` / `!hasTokens` / expired) | [`index.php`](www/index.php), `api/install.php`. KB: [tokens-save-only-on-install](/data/kb/b24-local-app-tokens-save-only-on-install.md) |
| DOMAIN resolve chain (POST.DOMAIN → existing → REFERER not-self) | `B24::saveTokensFromInstall` в [`api/b24.php`](www/api/b24.php). KB: [marketplace-install-post-no-domain](/data/kb/b24-marketplace-install-post-no-domain.md) |
| `installFinish` маркер | `installFinishedAt` / `installFinishedUsers` в `tokens.json`, рендер `renderInstallFinishPage()` |
| Двухфазная установка | reload через 700ms в `renderInstallFinishPage()`. KB: [two-phase-install](/data/kb/b24-local-app-two-phase-install.md) |
| Защита state через `<?php exit;?>` (без `.htaccess`) | [`api/store.php`](www/api/store.php) |
| Cache-bust `?v=<mtime>` для css/js | `preg_replace_callback` в [`index.php`](www/index.php) |
| Admin-gate через `user.admin` | [`api/session.php`](www/api/session.php) |
| Idempotent `placement.bind` | unbind перед bind в [`api/bind.php`](www/api/bind.php) |
| CRON anti-overlap (flock) | [`bin/process.php`](www/bin/process.php) |
| iframe X-Frame-Options / CSP (опционально) | закомментированный snippet в `index.php` — раскомментировать на коробке клиента |

Полный install-чек-лист и каноны жесткых вопросов: [/data/kb/rule-b24-install-checklist.md](/data/kb/rule-b24-install-checklist.md).

## Как создать новый проект

```bash
# 1. В портале нажми «+ Шаблон» → выбери «PHP/REST/API Битрикс24» →
#    введи имя проекта (a-z, 0-9, дефис). Файлы будут в твоём workspace.
cd ~/workspace/<slug>

# 2. Удалить шаблонный README (или переписать под проект)
# Оставить CLAUDE.md — пройти онбординг внутри проекта.

# 3. Раскатить www/ на хостинг (Netangels: ~/<DOMAIN>/www/<slug>/)
rsync -avz www/ user@host:~/<DOMAIN>/www/<slug>/

# 4. Открыть https://<host>/<slug>/init.php в браузере.
#    Он создаст env.php с автоматически определёнными APP_URL/APP_PATH/DATA_ROOT.
#    Если уже есть env.php — сохранит существующие B24_CLIENT_ID/SECRET.

# 5. Создать local-app на портале Б24 (Разработчикам → Другое → Локальное приложение):
#    - Path обработчика = https://<host>/<slug>/
#    - Path установки   = https://<host>/<slug>/
#    - Скоупы           = crm user placement (минимум; подмени под нужды проекта)

# 6. Вписать B24_CLIENT_ID / B24_CLIENT_SECRET из карточки local-app в env.php.

# 7. Удалить init.php через файловый менеджер (он одноразовый).

# 8. Открыть приложение из меню Б24-портала — двухфазный install-flow отработает сам.
```

## Что заменить под конкретный проект

Перед тем как делать бизнес-логику:

1. **`template.html`** — header'ы, title, UI-блоки
2. **`api/bind.php`** — массив `$placements` (раскомментировать нужные)
3. **`bin/process.php` → `runJob()`** — тело cron-задачи (если нужна)
4. **Новые endpoint'ы в `api/`** — свои `<endpoint>.php` под бизнес-операции
5. **`CLAUDE.md`** — пройти онбординг для конкретного проекта (заменит шаблонный текст)

## Что НЕ менять без причины

- **`api/store.php`** — защита через `<?php exit;?>` критична. Не выносить state в обычный `.json`
- **`api/b24.php` `saveTokensFromInstall`** — там цепочка резолва DOMAIN и not-self фильтр. Любая правка может сломать установку на cloud / коробке
- **`api/session.php`** — admin-gate через `user.admin` REST. Не ослаблять до простого «есть ли AUTH_ID»
- **Save-policy токенов в `index.php`** — `$isFirst || $isFormal || expired`. Не сохранять на каждом POST'е, иначе admin-токен затрётся (см. заметку)

## Хостинг

Дефолт — **Netangels** (zorintest), `testzorin.na4u.ru/<slug>/`. Шаблон не привязан жёстко к Netangels — `<?php exit;?>` защита работает на любом shared-хостинге с PHP-handler'ом, `.htaccess` не используется.

При переезде на коробку клиента или другой хостинг — раскомментировать iframe-headers snippet в `index.php` если iframe в Б24 пустой.
