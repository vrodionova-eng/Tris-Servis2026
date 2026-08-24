// Точка входа фронта. Подменить тело под бизнес-логику проекта.
//
// Что уже работает:
//  - window.APP_SESSION — токен сессии (инжектится index.php после admin-gate)
//  - window.BX24       — Б24 JS SDK (подгружен через template.html)
//
// Внизу пара helper'ов для типового кейса «дёрнуть наш api-endpoint с токеном».

(function () {
  'use strict';

  var bx24State = document.getElementById('bx24-state');
  var sessionState = document.getElementById('session-state');

  if (sessionState) {
    sessionState.textContent = window.APP_SESSION ? 'OK' : 'нет — открой через Б24';
    sessionState.className = window.APP_SESSION ? 'ok' : 'muted-tag';
  }

  if (window.BX24) {
    BX24.init(function () {
      if (bx24State) {
        bx24State.textContent = 'OK';
        bx24State.className = 'ok';
      }
      // Здесь можно дёргать BX24.callMethod, BX24.placement.info() и т.д.
    });
  } else if (bx24State) {
    bx24State.textContent = 'не загружен (открыто вне Б24?)';
    bx24State.className = 'muted-tag';
  }

  // Helper для вызовов нашего бэкенда с прикреплённым session-token.
  // Использовать: api('GET', 'api/my-endpoint.php').then(data => ...).
  window.api = function (method, path, body) {
    return fetch(path, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'X-App-Session': window.APP_SESSION || '',
      },
      body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  };
})();
