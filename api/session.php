<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/b24.php';

const SESSION_TTL    = 28800;
const SESSION_COOKIE = 'gsp_session';

function loadSessions(): array {
    $d = storeRead(SESSIONS_FILE);
    return is_array($d) ? $d : [];
}

function saveSessions(array $s): void {
    storeWrite(SESSIONS_FILE, $s);
}

function cleanupSessions(): array {
    $sessions = loadSessions();
    $now = time();
    $alive = array_values(array_filter($sessions, fn($x) => ($x['expiresAt'] ?? 0) > $now));
    if (count($alive) !== count($sessions)) saveSessions($alive);
    return $alive;
}

function findSessionByToken(string $token): ?array {
    if ($token === '') return null;
    foreach (cleanupSessions() as $s) {
        if (hash_equals((string)$s['token'], $token)) return $s;
    }
    return null;
}

function setSessionCookie(string $token, int $expires): void {
    $appUrl  = defined('APP_URL') ? APP_URL : '';
    $cookiePath = $appUrl !== '' ? (rtrim(parse_url($appUrl, PHP_URL_PATH) ?: '/', '/') . '/') : '/';
    setcookie(SESSION_COOKIE, $token, [
        'expires'  => $expires,
        'path'     => $cookiePath,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
}

function createSession(): array {
    $token = bin2hex(random_bytes(16));
    $session = [
        'token'     => $token,
        'createdAt' => time(),
        'expiresAt' => time() + SESSION_TTL,
    ];
    $sessions = cleanupSessions();
    $sessions[] = $session;
    saveSessions($sessions);
    setSessionCookie($token, $session['expiresAt']);
    return $session;
}

/** Причина отказа выдачи сессии — для index.php-страницы. */
function sessionDenialReason(): ?string {
    return $GLOBALS['__session_denial_reason'] ?? null;
}

function b24IsPortalAdmin(string $authId, string $domain): bool {
    if ($authId === '' || $domain === '') return false;
    $url = 'https://' . $domain . '/rest/user.admin.json?auth=' . urlencode($authId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) return false;
    $data = json_decode((string)$resp, true);
    return is_array($data) && !empty($data['result']);
}

function tryCreateSessionFromB24Post(): ?array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return null;

    $input = array_merge($_GET, $_POST);
    if (empty($input['DOMAIN']) || empty($input['APP_SID'])) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $j = json_decode($raw, true);
            if (is_array($j)) $input = array_merge($input, $j);
            else { parse_str($raw, $p); if ($p) $input = array_merge($input, $p); }
        }
    }

    $domain = (string)($input['DOMAIN']  ?? '');
    $appSid = (string)($input['APP_SID'] ?? '');
    $authId = (string)($input['AUTH_ID'] ?? '');
    if ($appSid === '') return null;

    // Portal-fence: DOMAIN из POST совпадает с сохранённым domain.
    $stored = b24Portal();
    if (!$stored) return null;
    if ($domain !== $stored) return null;

    // REFERER может быть либо с B24-портала (штатный iframe-open), либо с нашего
    // APP_URL (iframe-reload после BX24.installFinish() — JS в нашей же странице
    // триггерит POST, REFERER ставится на её URL). Оба легитимны.
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $isB24Ref  = (bool)preg_match('#^https://' . preg_quote($stored, '#') . '/#', $referer);
    $isSelfRef = defined('APP_URL') && APP_URL !== ''
                 && strpos($referer, rtrim(APP_URL, '/') . '/') === 0;
    if (!$isB24Ref && !$isSelfRef) return null;

    // Admin-gate: only-admin доступ к приложению.
    if ($authId === '' || !b24IsPortalAdmin($authId, $stored)) {
        $GLOBALS['__session_denial_reason'] = 'not_admin';
        return null;
    }

    return createSession();
}

function findSessionFromCookie(): ?array {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    return $token ? findSessionByToken((string)$token) : null;
}

function findSessionFromHeader(): ?array {
    $token = $_SERVER['HTTP_X_APP_TOKEN'] ?? '';
    return $token ? findSessionByToken((string)$token) : null;
}

/** Для API-эндпоинтов: возвращает session или отдаёт 403 JSON. */
function requireSession(): array {
    $session = findSessionFromHeader() ?? findSessionFromCookie();
    if (!$session) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Доступ только из Bitrix24. Открой приложение в левом меню портала.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $session;
}
