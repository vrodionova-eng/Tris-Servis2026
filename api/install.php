<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/b24.php';

function showResult(string $title, bool $ok, bool $shouldFinish, array $extra = []): void {
    header('Content-Type: text/html; charset=utf-8');
    $color = $ok ? '#5ec35e' : '#e35454';
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title>';
    if ($shouldFinish) echo '<script src="//api.bitrix24.com/api/v1/"></script>';
    echo '<style>body{font-family:system-ui,sans-serif;padding:40px;background:#eef2f4;color:#333;text-align:center}h1{color:' . $color . ';margin:0 0 8px}p{color:#666}</style></head><body>';
    echo '<h1>' . htmlspecialchars($title) . '</h1>';
    if (!$ok) {
        echo '<pre style="background:#fff;border:1px solid #dde1e7;border-radius:6px;padding:14px;text-align:left;display:inline-block">' . htmlspecialchars(json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '</body></html>';
        return;
    }
    echo '<p>Открой приложение из меню портала.</p>';
    if ($shouldFinish) {
        echo '<script>try { BX24.init(function(){ BX24.installFinish(); }); } catch(e) {}</script>';
    }
    echo '</body></html>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        $d = json_decode((string)$raw, true);
        if (is_array($d)) $body = $d;
        else { parse_str((string)$raw, $p); if ($p) $body = $p; }
    }

    if (!is_dir(DATA_ROOT)) @mkdir(DATA_ROOT, 0700, true);
    $cid = defined('B24_CLIENT_ID') ? B24_CLIENT_ID : '';
    $sec = defined('B24_CLIENT_SECRET') ? B24_CLIENT_SECRET : '';
    $b24 = new B24($cid, $sec);

    $isFirst  = !$b24->hasTokens();
    $isFormal = ($body['INSTALL'] ?? '') === 'Y' || ($body['event'] ?? '') === 'ONAPPINSTALL';
    if ($isFirst || $isFormal || $b24->isAccessExpired()) {
        try {
            $b24->saveTokensFromInstall($body, (string)($_SERVER['HTTP_REFERER'] ?? ''));
        } catch (RuntimeException $e) {
            showResult('Ошибка установки', false, false, ['error' => $e->getMessage()]);
            exit;
        }
        showResult('Приложение установлено', true, $isFirst);
    } else {
        showResult('Приложение уже установлено', true, false);
    }
    exit;
}

// GET ?code=... — OAuth callback (ручная установка через браузер).
if (!empty($_GET['code'])) {
    $cid = defined('B24_CLIENT_ID') ? B24_CLIENT_ID : '';
    $sec = defined('B24_CLIENT_SECRET') ? B24_CLIENT_SECRET : '';
    $code = $_GET['code'];
    $postBody = http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => $cid,
        'client_secret' => $sec,
        'redirect_uri'  => b24CurrentUrl(),
        'code'          => $code,
    ]);
    $ch = curl_init('https://oauth.bitrix.info/oauth/token/');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $data = json_decode((string)$raw, true) ?: [];
    if (empty($data['access_token'])) {
        showResult('Ошибка обмена code → token', false, false, $data);
        exit;
    }

    $b24 = new B24($cid, $sec);
    $synthetic = [
        'AUTH_ID'      => $data['access_token'],
        'REFRESH_ID'   => $data['refresh_token']   ?? '',
        'AUTH_EXPIRES' => $data['expires_in']      ?? 3600,
        'DOMAIN'       => $data['domain']          ?? '',
        'member_id'    => $data['member_id']       ?? '',
        'PROTOCOL'     => 'https',
    ];
    try {
        $b24->saveTokensFromInstall($synthetic, (string)($_SERVER['HTTP_REFERER'] ?? ''));
    } catch (RuntimeException $e) {
        showResult('Ошибка сохранения токенов', false, false, ['error' => $e->getMessage()]);
        exit;
    }
    showResult('Авторизация OK', true, false);
    exit;
}

showResult('Приложение ещё не установлено', false, false, [
    'hint' => 'Открой приложение из меню портала Bitrix24 — установка пройдёт автоматически.',
]);
