<?php
declare(strict_types=1);

/**
 * Защищённый файловый store: .php-файлы с `<?php exit; ?>` префиксом.
 * Прямой HTTP-запрос → PHP-интерпретатор отрабатывает exit → пустой ответ
 * (защита-в-глубину; .htaccess не требуется).
 *
 * Все state-файлы приложения (b24-tokens, settings, sessions, tokens,
 * b24-smart) хранятся через эти helpers.
 */

function storeEncode(array $data): string {
    return "<?php exit; /* JSON state — прямой HTTP закрыт PHP-exit'ом. */ ?>\n"
         . (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function storeDecode(string $content): ?array {
    $closeTag = '?' . '>';
    $pos = strpos($content, $closeTag);
    if ($pos === false) {
        // Legacy: сырой JSON без prefix'а — пробуем как есть.
        $j = json_decode($content, true);
        return is_array($j) ? $j : null;
    }
    $j = json_decode(ltrim(substr($content, $pos + 2)), true);
    return is_array($j) ? $j : null;
}

/** Атомарная запись + chmod 600. */
function storeWrite(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $tmp = $file . '.tmp';
    file_put_contents($tmp, storeEncode($data));
    @chmod($tmp, 0600);
    rename($tmp, $file);
}

/** Чтение из .php-store с legacy-fallback на .json (одноимённый файл). */
function storeRead(string $file): ?array {
    if (is_file($file)) {
        return storeDecode((string)file_get_contents($file));
    }
    $legacy = preg_replace('/\.php$/', '.json', $file);
    if ($legacy !== $file && is_file($legacy)) {
        $data = json_decode((string)file_get_contents($legacy), true);
        if (is_array($data)) {
            storeWrite($file, $data);
            @unlink($legacy);
            return $data;
        }
    }
    return null;
}

/**
 * Host из HTTP_REFERER принимать как domain Б24-портала?
 * «not-self»: любой host, отличный от APP_URL host'а. Покрывает cloud
 * (*.bitrix24.*) и on-prem-коробки, но отвергает self-REFERER при
 * installFinish-reload.
 */
function isExternalB24Host(string $host): bool {
    if ($host === '') return false;
    $appUrl = defined('APP_URL') ? APP_URL : '';
    if ($appUrl === '') return true;
    $ownHost = parse_url($appUrl, PHP_URL_HOST);
    if (!is_string($ownHost) || $ownHost === '') return true;
    return strcasecmp($host, $ownHost) !== 0;
}
