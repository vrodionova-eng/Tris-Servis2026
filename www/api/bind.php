<?php
// Утилита: запустить placement.bind для всех плейсментов проекта.
// Идемпотентна: перед bind делает unbind с тем же HANDLER'ом.
//
// Использовать:
// 1. После установки приложения, когда нужно вручную пересобрать плейсменты
//    (например, поменялось название или добавили новый placement).
// 2. В двухфазном install-flow — фаза 2 (после reload) делает то же самое
//    автоматически. См. KB: b24-local-app-two-phase-install.
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/b24.php';

requireSession();

// ── Спецификация плейсментов ─────────────────────────────────────────────
// Заполнить под нужды проекта. Список placement'ов и их параметров —
// см. https://apidocs.bitrix24.ru/api-reference/widgets/.
//
// На cloud-Б24 LEFT_MENU обычно биндится автоматически — пункт добавляется
// без `placement.bind LEFT_MENU`. См. KB: local-app-left-menu-auto-bind.
// На коробке — `placement.bind LEFT_MENU` нужен явно.
$appUrl = rtrim(APP_URL, '/') . '/';
$placements = [
    // Пример 1: вкладка в карточке сделки.
    // [
    //     'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
    //     'HANDLER'   => $appUrl,
    //     'TITLE'     => 'Моя вкладка',
    //     'DESCRIPTION' => '',
    // ],
    // Пример 2: пункт в левом меню (нужно на коробке).
    // [
    //     'PLACEMENT' => 'LEFT_MENU',
    //     'HANDLER'   => $appUrl,
    //     'TITLE'     => 'Моё приложение',
    // ],
];

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><meta charset="UTF-8"><style>body{font-family:system-ui,sans-serif;padding:30px;background:#eef2f4;color:#333;max-width:900px;margin:0 auto}h1{color:#2c3e50}.ok{color:#1e6b1e}.err{color:#b03030}pre{background:#fff;border:1px solid #dde1e7;border-radius:6px;padding:14px;font-size:12px;overflow:auto;white-space:pre-wrap}</style>';

if (empty($placements)) {
    echo '<h1>Список плейсментов пуст</h1>';
    echo '<p>Открой <code>api/bind.php</code>, раскомментируй нужные плейсменты в массиве <code>$placements</code>, '
       . 'и обнови эту страницу.</p>';
    exit;
}

try {
    $b24 = b24();
} catch (Throwable $e) {
    echo "<h1 class='err'>✗ " . htmlspecialchars($e->getMessage()) . "</h1>";
    exit;
}

echo '<h1>placement.bind — результаты</h1>';
foreach ($placements as $p) {
    $code = $p['PLACEMENT'];
    // Идемпотентность: снять старый bind с тем же HANDLER'ом.
    try { $b24->call('placement.unbind', ['PLACEMENT' => $code, 'HANDLER' => $p['HANDLER']]); } catch (Throwable $e) {}
    $res = $b24->call('placement.bind', $p);
    $ok  = !isset($res['error']);
    $cls = $ok ? 'ok' : 'err';
    $sym = $ok ? '✓' : '✗';
    echo "<h3 class='{$cls}'>{$sym} {$code}</h3>";
    echo "<pre>" . htmlspecialchars(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
}
