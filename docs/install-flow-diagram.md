# Install-flow — диаграмма POST'ов

Шаблон делает двухфазный install (см. KB [b24-local-app-two-phase-install](/data/kb/b24-local-app-two-phase-install.md)).
Эта диаграмма — что когда происходит, чтобы при дебаге понимать «на каком POST'е сейчас».

## Полный сценарий: чистая установка на новом портале

```
┌──────────────────┐                    ┌──────────────────────┐
│ Админ в Б24      │                    │ Наш сервер           │
│ нажимает         │                    │ index.php            │
│ «Установить»     │                    │                      │
└────────┬─────────┘                    └──────────┬───────────┘
         │                                         │
         │   POST /  (INSTALL=Y, AUTH_ID,          │
         │            REFRESH_ID, SERVER_ENDPOINT, │
         │            APPLICATION_TOKEN,           │
         │            member_id, DOMAIN*)          │
         ├────────────────────────────────────────►│
         │                                         │
         │                                         │ 1. saveTokensFromInstall()
         │                                         │    - резолв DOMAIN (POST → existing → REFERER)
         │                                         │    - tokens.json создан
         │                                         │
         │                                         │ 2. resolveUserIdFromAuth() через user.current
         │                                         │
         │                                         │ 3. needsInstallFinishFor() = true
         │                                         │
         │                                         │ 4. renderInstallFinishPage()
         │                                         │    HTML с BX24.installFinish() + reload 700ms
         │  ◄──────────────────────────────────────┤
         │                                         │
         │   BX24.installFinish()                  │
         │   Б24 переключает INSTALLED:false→true  │
         │                                         │
         │   После 700ms: location.reload()        │
         │                                         │
         │   POST /  (AUTH_ID, ...)  — open #2     │
         ├────────────────────────────────────────►│
         │                                         │
         │                                         │ 1. saveTokensFromInstall()
         │                                         │    !isFirst, !isFormal → skip
         │                                         │    (admin-токен остаётся)
         │                                         │
         │                                         │ 2. resolveUserIdFromAuth()
         │                                         │
         │                                         │ 3. needsInstallFinishFor() = false
         │                                         │    (user уже в installFinishedUsers)
         │                                         │
         │                                         │ 4. session gate → admin OK
         │                                         │
         │                                         │ 5. render template.html
         │  ◄──────────────────────────────────────┤
         │                                         │
         │   Видит UI приложения                   │
```

\* `DOMAIN` на cloud не приходит — резолвим из `SERVER_ENDPOINT` через REFERER (фильтр not-self).
KB: [b24-marketplace-install-post-no-domain](/data/kb/b24-marketplace-install-post-no-domain.md).

## Открытие не-админом

```
┌──────────────────┐                    ┌──────────────────────┐
│ Не-админ в Б24   │                    │ index.php            │
│ открывает app    │                    │                      │
└────────┬─────────┘                    └──────────┬───────────┘
         │                                         │
         │   POST /  (AUTH_ID юзера, не админа)    │
         ├────────────────────────────────────────►│
         │                                         │
         │                                         │ 1. saveTokensFromInstall()
         │                                         │    !isFirst, !isFormal, !expired → skip
         │                                         │    (admin-токен сохранён!)
         │                                         │
         │                                         │ 2. needsInstallFinishFor() = false
         │                                         │
         │                                         │ 3. session gate
         │                                         │    user.admin?auth=<AUTH_ID> → false
         │                                         │    → 403 "Доступ только для администраторов"
         │  ◄──────────────────────────────────────┤
         │                                         │
```

KB: [b24-local-app-tokens-save-only-on-install](/data/kb/b24-local-app-tokens-save-only-on-install.md) —
**критично не сохранять токены этого юзера**, иначе админ-токен затрётся и proxy.php
работающий под app-token начнёт возвращать ACCESS_DENIED.

## Reinstall в Developer-карточке

Кейс: админ delete'нул local-app, потом recreate'нул в той же карточке (или просто «Переустановить»).

```
B24 шлёт: POST /  (INSTALL=Y, новый APPLICATION_TOKEN, тот же member_id)
                                         │
                                         │ saveTokensFromInstall()
                                         │ isFormal=true → перезаписать tokens.json
                                         │ (новый APPLICATION_TOKEN, новый AUTH_ID)
                                         │
                                         │ ⚠ state.installFinished может оставаться true
                                         │   из прошлой инсталляции — мы рендерим
                                         │   нормальный UI, но app.info.INSTALLED=false
                                         │   → не-админы получают «приложение не установлено»
                                         │
                                         │ Лечение: см. KB [b24-local-app-install-finish-per-user]
                                         │ — сбросить state, открыть админом, installFinish заново
```

Полный guard на APPLICATION_TOKEN mismatch — KB
[b24-local-app-app-reinstall-application-token-mismatch](/data/kb/b24-local-app-app-reinstall-application-token-mismatch.md).
В этом шаблоне базовый guard уже есть через `isFormal` save-policy, но более тонкая защита
(сравнивать APPLICATION_TOKEN явно) добавляется при необходимости.
