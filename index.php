<?php
// Главный handler — один URL для install POST'а и runtime'а (single-tenant).
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/api/store.php';
require_once __DIR__ . '/api/b24.php';
require_once __DIR__ . '/api/session.php';

// ── iframe-headers (опционально) ─────────────────────────────────────────
// На Netangels и большинстве shared-хостингов веб-сервер НЕ ставит X-Frame-Options,
// поэтому Б24 спокойно встраивает приложение в iframe без нашей помощи.
//
// На коробке клиента (свой сервер с Bitrix Env / Apache / nginx) часто ставится
// `X-Frame-Options: SAMEORIGIN` глобально для безопасности — и iframe в Б24
// становится пустым, в консоли «refused to connect».
//
// Если столкнулся с этим — раскомментируй блок ниже, замени список Б24-TLD
// при необходимости (особенно если у клиента кастомный домен на коробке).
//
// header_remove('X-Frame-Options');
// header("Content-Security-Policy: frame-ancestors "
//      . "https://*.bitrix24.ru https://*.bitrix24.com https://*.bitrix24.kz "
//      . "https://*.bitrix24.by https://*.bitrix24.ua https://*.bitrix24.de "
//      . "https://*.bitrix24.eu https://*.bitrix24.com.br "
//      . "'self'");
//
// См. /data/kb/b24-iframe-x-frame-options-csp.md для подробностей.

// ── 1. INSTALL POST handler ──────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$post = $_POST;
if (empty($post) && $method === 'POST') {
    $raw = file_get_contents('php://input');
    $d = json_decode((string)$raw, true);
    if (is_array($d)) $post = $d;
    else { parse_str((string)$raw, $p); if ($p) $post = $p; }
}

$authId = (string)($post['AUTH_ID'] ?? '');
$needInstallFinish = false;

if ($method === 'POST' && $authId !== '') {
    if (!is_dir(DATA_ROOT)) @mkdir(DATA_ROOT, 0700, true);

    $cid = defined('B24_CLIENT_ID') ? B24_CLIENT_ID : '';
    $sec = defined('B24_CLIENT_SECRET') ? B24_CLIENT_SECRET : '';
    $b24 = new B24($cid, $sec);

    // Save policy: tokens перезаписываем при первой установке/формальном
    // (re)install/expired. На штатных open'ах не трогаем, чтобы не затереть
    // admin-токен токеном случайного юзера. KB: tokens-save-only-on-install.
    $isFirst  = !$b24->hasTokens();
    $isFormal = ($post['INSTALL'] ?? '') === 'Y' || ($post['event'] ?? '') === 'ONAPPINSTALL';
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($isFirst || $isFormal || $b24->isAccessExpired()) {
        try {
            $b24->saveTokensFromInstall($post, $referer);
        } catch (RuntimeException $e) {
            http_response_code(400); echo htmlspecialchars($e->getMessage()); exit;
        }
    }

    // Per-user installFinish.
    $currentUserId = resolveUserIdFromAuth($authId, (string)$b24->domain());
    if ($b24->needsInstallFinishFor($currentUserId)) {
        $needInstallFinish = true;
        $b24->markUserFinished($currentUserId ?? '_unknown');
    } elseif ($currentUserId !== null) {
        $b24->markUserFinished($currentUserId);
    }
}

// ── 2. installFinish-страница ────────────────────────────────────────────
if ($needInstallFinish) {
    renderInstallFinishPage();
    exit;
}

// ── 3. Session gate ──────────────────────────────────────────────────────
$session = tryCreateSessionFromB24Post() ?? findSessionFromCookie();

if (!$session) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $reason = sessionDenialReason();
    if ($reason === 'not_admin') {
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Доступ только для администраторов</title>'
           . '<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 20px;color:#333;text-align:center}h1{color:#b07b00;margin-bottom:8px}p{line-height:1.55;color:#555}</style>'
           . '</head><body>'
           . '<h1>Доступ только для администраторов</h1>'
           . '<p>Это приложение доступно только администраторам портала Bitrix24. '
           . 'Если тебе нужен доступ — попроси админа открыть приложение и настроить тебя.</p>'
           . '</body></html>';
    } else {
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Доступ запрещён</title>'
           . '<style>body{font-family:system-ui,sans-serif;max-width:520px;margin:80px auto;padding:0 20px;color:#333;text-align:center}h1{color:#b03030;margin-bottom:8px}p{line-height:1.5;color:#555}</style>'
           . '</head><body>'
           . '<h1>Доступ запрещён</h1>'
           . '<p>Это приложение работает только из портала Bitrix24.<br>Открой его из меню портала.</p>'
           . '</body></html>';
    }
    exit;
}

// ── 4. Render app ────────────────────────────────────────────────────────
$html = file_get_contents(__DIR__ . '/template.html');

// Cache-bust: ?v=<mtime> к local css/js.
$html = preg_replace_callback(
    '~(href|src)="((?:css|js)/[^"?#]+)"~',
    function ($m) {
        $p = __DIR__ . '/' . $m[2];
        $v = is_file($p) ? filemtime($p) : '';
        return $m[1] . '="' . $m[2] . ($v ? '?v=' . $v : '') . '"';
    },
    $html
);

$inject = '<script>window.APP_SESSION = ' . json_encode($session['token']) . ';</script>';
echo str_replace('</head>', $inject . '</head>', $html);
