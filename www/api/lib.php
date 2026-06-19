<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';

/**
 * Generic-хелперы шаблона. Здесь живёт всё, что НЕ привязано к Б24 или к
 * конкретной бизнес-логике: загрузка/запись settings, обёртка над cURL для
 * HTTP-вызовов внешних API.
 *
 * Сюда дописывать новые helper'ы, которые имеют смысл в любом проекте.
 * Бизнес-специфичные функции — в отдельные файлы под /api/.
 */

/** Settings — всё, что админ настраивает через UI приложения. */
function loadSettings(): array {
    $d = storeRead(SETTINGS_FILE);
    return is_array($d) ? $d : [];
}

function saveSettings(array $s): void {
    storeWrite(SETTINGS_FILE, $s);
}

/**
 * Универсальная JSON-обёртка над cURL. На 4xx/5xx бросает RuntimeException
 * с message из тела ответа (Б24 и большинство REST-API шлют structured errors).
 *
 * Использовать для интеграций с внешними API (если будут).
 * Для вызовов Б24 REST — использовать B24->call() / B24->batch() (см. b24.php),
 * там уже встроены OAuth, refresh и обработка ошибок.
 */
function httpJson(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if ($body === false) throw new RuntimeException("HTTP error: $err");
    $data = json_decode((string)$body, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? $data['error_description'] ?? $body;
        throw new RuntimeException("HTTP $code: $msg");
    }
    return is_array($data) ? $data : [];
}
