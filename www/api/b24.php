<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';

/**
 * Класс B24: OAuth-обёртка REST API Битрикс24 (single-tenant).
 * Хранит токены в B24_TOKENS_FILE, сам refresh'ит при expired_token,
 * сам persist'ит изменения через storeWrite.
 *
 * KB: b24-marketplace-install-post-no-domain (фильтр REFERER + резолв domain).
 */
final class B24 {
    private string $clientId;
    private string $clientSecret;
    private array  $tokens = [];

    public function __construct(string $clientId, string $clientSecret) {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $j = storeRead(B24_TOKENS_FILE);
        if (is_array($j)) $this->tokens = $j;
    }

    public function memberId(): ?string { return $this->tokens['member_id'] ?? null; }
    public function domain(): ?string   { return $this->tokens['domain']    ?? null; }
    public function hasTokens(): bool   { return !empty($this->tokens['access_token']); }
    public function tokens(): array     { return $this->tokens; }

    public function isAccessExpired(): bool {
        $exp = (int)($this->tokens['expires_at'] ?? 0);
        return $exp > 0 && time() >= $exp;
    }

    /**
     * Сохранить токены из install POST Б24. DOMAIN/member_id могут не прилететь —
     * резолвим из существующих токенов или HTTP_REFERER (фильтр «not-self»).
     */
    public function saveTokensFromInstall(array $post, ?string $referer = null): void {
        $get = fn($k) => $post[$k] ?? $post[strtolower($k)] ?? null;

        $access  = (string)($get('AUTH_ID')    ?? '');
        $refresh = (string)($get('REFRESH_ID') ?? '');
        if ($access === '' || $refresh === '') {
            throw new RuntimeException('B24 POST без AUTH_ID/REFRESH_ID');
        }

        $domain = (string)($get('DOMAIN') ?? '');
        if ($domain === '') $domain = (string)($this->tokens['domain'] ?? '');
        if ($domain === '' && is_string($referer) && $referer !== '') {
            $h = parse_url($referer, PHP_URL_HOST);
            if (is_string($h) && isExternalB24Host($h)) $domain = $h;
        }
        if ($domain === '') {
            throw new RuntimeException('B24 POST: domain не определить (нет в POST/tokens/referer)');
        }

        $memberId = (string)($get('member_id') ?? $this->tokens['member_id'] ?? '');
        $expires  = (int)($get('AUTH_EXPIRES') ?? 3600);
        $protocol = (string)($get('PROTOCOL')  ?? 'https');

        $this->tokens = [
            'access_token'         => $access,
            'refresh_token'        => $refresh,
            'expires_at'           => time() + $expires - 60,
            'expires_in'           => $expires,
            'member_id'            => $memberId,
            'domain'               => $domain,
            'protocol'             => $protocol,
            'client_endpoint'      => $protocol . '://' . $domain . '/rest/',
            'client_id'            => $this->clientId,
            'installFinishedAt'    => $this->tokens['installFinishedAt']    ?? date('c'),
            'installFinishedUsers' => (array)($this->tokens['installFinishedUsers'] ?? []),
        ];
        $this->persist();
    }

    public function markUserFinished(string $userId): void {
        $users = (array)($this->tokens['installFinishedUsers'] ?? []);
        if ($userId !== '_unknown') {
            $users = array_values(array_filter($users, fn($u) => $u !== '_unknown'));
        }
        if (!in_array($userId, $users, true)) $users[] = $userId;
        $this->tokens['installFinishedUsers'] = $users;
        if (empty($this->tokens['installFinishedAt'])) $this->tokens['installFinishedAt'] = date('c');
        $this->persist();
    }

    public function needsInstallFinishFor(?string $userId): bool {
        $users = (array)($this->tokens['installFinishedUsers'] ?? []);
        if (empty($users)) return true;
        if (in_array('_unknown', $users, true)) return false;
        if ($userId === null) return false;
        return !in_array($userId, $users, true);
    }

    public function call(string $method, array $params = []): array {
        if (!$this->hasTokens()) throw new RuntimeException('B24: токенов нет — приложение не установлено');
        $res = $this->doCall($method, $params);
        if (!empty($res['error']) && in_array($res['error'], ['expired_token', 'invalid_token'], true)
            && !empty($this->tokens['refresh_token'])) {
            $this->refreshAccessToken();
            $res = $this->doCall($method, $params);
        }
        return $res;
    }

    public function batch(array $commands): array {
        $cmd = [];
        foreach ($commands as $k => [$m, $p]) {
            $cmd[$k] = $m . '?' . http_build_query($p);
        }
        return $this->call('batch', ['halt' => 0, 'cmd' => $cmd]);
    }

    private function doCall(string $method, array $params): array {
        $endpoint = (string)($this->tokens['client_endpoint'] ?? '');
        if ($endpoint === '') $endpoint = 'https://' . (string)($this->tokens['domain'] ?? '') . '/rest/';
        $url = rtrim($endpoint, '/') . '/' . $method . '.json';
        $params['auth'] = $this->tokens['access_token'] ?? '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) return ['error' => 'http_error'];
        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : ['error' => 'bad_json', 'raw' => $raw];
    }

    private function refreshAccessToken(): void {
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => (string)($this->tokens['refresh_token'] ?? ''),
        ]);
        $ch = curl_init('https://oauth.bitrix.info/oauth/token/');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) throw new RuntimeException('B24 refresh: curl error');
        $data = json_decode((string)$raw, true) ?: [];
        if (empty($data['access_token'])) {
            throw new RuntimeException('B24 refresh: ' . ($data['error_description'] ?? 'no token'));
        }
        $expires = (int)($data['expires_in'] ?? 3600);
        $this->tokens['access_token']  = $data['access_token'];
        $this->tokens['refresh_token'] = $data['refresh_token'] ?? $this->tokens['refresh_token'];
        $this->tokens['expires_at']    = time() + $expires - 60;
        $this->tokens['expires_in']    = $expires;
        $this->persist();
    }

    public function persist(): void {
        storeWrite(B24_TOKENS_FILE, $this->tokens + ['saved_at' => date('c')]);
    }
}

// ── helpers ──────────────────────────────────────────────────────────────

/** Фабрика — глобальный экземпляр для install и API endpoint'ов. */
function b24(): B24 {
    $cid = defined('B24_CLIENT_ID') ? B24_CLIENT_ID : '';
    $sec = defined('B24_CLIENT_SECRET') ? B24_CLIENT_SECRET : '';
    $b24 = new B24($cid, $sec);
    if (!$b24->hasTokens()) {
        throw new RuntimeException('Нет B24-токенов. Открой приложение из портала Bitrix24.');
    }
    return $b24;
}

/** URL текущего endpoint'а из $_SERVER — redirect_uri OAuth-flow. */
function b24CurrentUrl(): string {
    if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) return '';
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return $proto . '://' . $_SERVER['HTTP_HOST'] . $path;
}

/** Domain Б24-портала из сохранённых токенов. null если ещё не установлено. */
function b24Portal(): ?string {
    $j = storeRead(B24_TOKENS_FILE);
    $d = is_array($j) ? ($j['domain'] ?? null) : null;
    return (is_string($d) && $d !== '') ? $d : null;
}

/** Резолв userId по AUTH_ID через REST user.current. */
function resolveUserIdFromAuth(string $authId, string $domain): ?string {
    if ($authId === '' || $domain === '') return null;
    $url = 'https://' . $domain . '/rest/user.current.json?auth=' . urlencode($authId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) return null;
    $data = json_decode((string)$resp, true);
    $id = $data['result']['ID'] ?? null;
    return $id ? (string)$id : null;
}

function renderInstallFinishPage(): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Установка</title>'
       . '<script src="//api.bitrix24.com/api/v1/"></script>'
       . '<style>body{font-family:system-ui,sans-serif;padding:60px 20px;text-align:center;color:#333;max-width:480px;margin:0 auto}h1{color:#1e6b1e;margin:0 0 8px}p{color:#666;line-height:1.5}</style>'
       . '</head><body>'
       . '<h1>Приложение устанавливается…</h1>'
       . '<p>Через секунду откроется интерфейс. Не закрывай вкладку.</p>'
       . '<script>document.addEventListener("DOMContentLoaded",function(){'
       . 'if(window.BX24)BX24.init(function(){try{BX24.installFinish();}catch(_){}'
       . 'setTimeout(function(){location.reload();},700);});});</script>'
       . '</body></html>';
}
