<?php
// Самонастройка приложения на новом хостинге (single-tenant).
// Открыть в браузере один раз: https://<host>/<path>/init.php

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$appPath = __DIR__;
$envFile = $appPath . '/env.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
$baseDir = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$appUrl = $scheme . '://' . $host . $baseDir;
$dataRoot = $appPath . '/data';

// Читаем существующий env.php (если есть) чтобы не затереть B24_CLIENT_ID/SECRET.
// env.php — PHP-файл с define()-ами; используем include в изолированном scope.
$existingClientId = '';
$existingClientSecret = '';
if (is_file($envFile)) {
    // Включаем существующий env.php — define'ы определятся в текущем процессе,
    // потом считаем их через constant(). Это безопасно: повторные define()
    // вернут notice, но не fatal.
    @include $envFile;
    if (defined('B24_CLIENT_ID'))     $existingClientId     = (string)B24_CLIENT_ID;
    if (defined('B24_CLIENT_SECRET')) $existingClientSecret = (string)B24_CLIENT_SECRET;
}

if (!is_dir($dataRoot)) @mkdir($dataRoot, 0700, true);
@chmod($dataRoot, 0700);

$content  = "<?php\n";
$content .= "// Конфигурация приложения. Записано init.php — отредактируй B24_*\n";
$content .= "// через файловый менеджер, если ещё пусты.\n";
$content .= "declare(strict_types=1);\n\n";
$content .= "// ── Bitrix24 (local-app) ──────────────────────────────────────────\n";
$content .= "define('B24_CLIENT_ID',     " . var_export($existingClientId, true) . ");\n";
$content .= "define('B24_CLIENT_SECRET', " . var_export($existingClientSecret, true) . ");\n\n";
$content .= "// ── Хост приложения (автоопределено init.php) ────────────────────\n";
$content .= "define('APP_URL',  " . var_export($appUrl, true) . ");\n";
$content .= "define('APP_PATH', " . var_export($appPath, true) . ");\n";
$content .= "define('DATA_ROOT', " . var_export($dataRoot, true) . ");\n\n";
$content .= "// ── Производные file-path константы ──────────────────────────────\n";
$content .= "define('B24_TOKENS_FILE', DATA_ROOT . '/b24-tokens.php');\n";
$content .= "define('SETTINGS_FILE',   DATA_ROOT . '/settings.php');\n";
$content .= "define('SESSIONS_FILE',   DATA_ROOT . '/sessions.php');\n";
$content .= "define('STATE_FILE',      DATA_ROOT . '/state.php');\n";

$tmp = $envFile . '.tmp';
file_put_contents($tmp, $content);
@chmod($tmp, 0600);
rename($tmp, $envFile);

header('Content-Type: text/html; charset=utf-8');
$cronCmd = '/usr/bin/php ' . $appPath . '/bin/process.php >/dev/null 2>&1';
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>init — настройка приложения</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 880px; margin: 30px auto; padding: 0 20px; color: #333; line-height: 1.5; }
  h1 { color: #1e6b1e; }
  h2 { color: #2c3e50; margin-top: 28px; border-top: 1px solid #e1e4e8; padding-top: 16px; }
  .row { display: flex; gap: 12px; margin: 6px 0; align-items: center; }
  .row .label { flex: 0 0 200px; color: #666; font-size: 13px; }
  .row .value { flex: 1; background: #f6f8fa; border: 1px solid #d1d5da; border-radius: 4px; padding: 7px 11px; font-family: ui-monospace, monospace; font-size: 13px; word-break: break-all; }
  .row button { background: #2c3e50; color: white; border: 0; padding: 7px 13px; border-radius: 4px; cursor: pointer; font-size: 13px; }
  .row button:hover { background: #34495e; }
  .row button.copied { background: #1e6b1e; }
  .warn { background: #fff8e1; border-left: 4px solid #f4b400; padding: 12px 16px; margin: 18px 0; }
  code { background: #f6f8fa; padding: 1px 6px; border-radius: 3px; font-size: 13px; }
</style></head>
<body>

<h1>✓ Готово! Приложение сконфигурировано.</h1>

<div class="warn">
  <b>⚠ Удалите этот файл (<code>init.php</code>)</b> через файловый менеджер хостинга.
  Повторный запуск перезапишет <code>env.php</code>.
</div>

<?php if ($existingClientId === ''): ?>
<div class="warn">
  В <code>env.php</code> сейчас пустые <code>B24_CLIENT_ID</code>/<code>B24_CLIENT_SECRET</code>.
  Создай local-app в Б24 на портале, скопируй creds и впиши их в <code>env.php</code> через
  файловый менеджер. После этого подключай приложение из Б24.
</div>
<?php endif; ?>

<h2>1. Bitrix24 → карточка local-app</h2>
<div class="warn">
  Оба URL ниже заканчиваются на <code>/index.php</code>, а не на <code>/</code>.
  Шаблон работает <b>без</b> <code>.htaccess</code>: на shared-хостингах
  глобальный <code>DirectoryIndex</code> обычно не включает <code>index.php</code>,
  поэтому handler URL без <code>/index.php</code> на конце вернёт 403. Указывать
  явный файл — единственный way, который работает на любом хостинге без
  AllowOverride/mod_rewrite.
</div>
<div class="row">
  <div class="label">Путь обработчика</div>
  <div class="value" id="v-handler"><?= htmlspecialchars($appUrl . '/index.php') ?></div>
  <button onclick="copy('v-handler', this)">Копировать</button>
</div>
<div class="row">
  <div class="label">Путь установки</div>
  <div class="value" id="v-install"><?= htmlspecialchars($appUrl . '/index.php') ?></div>
  <button onclick="copy('v-install', this)">Копировать</button>
</div>
<div class="row">
  <div class="label">Скоупы (минимум)</div>
  <div class="value" id="v-scopes">crm, user, placement</div>
  <button onclick="copy('v-scopes', this)">Копировать</button>
</div>
<div class="warn">
  Список скоупов — минимум для шаблона. Если в проекте нужны UF/смарт-процессы — добавь
  <code>userfieldconfig</code>, для списков — <code>lists</code>, для задач — <code>task</code> и т.д.
  Скоупы можно поменять в карточке local-app в любой момент + переустановить.
</div>

<h2>2. Cron — команда для планировщика хостинга</h2>
<div class="row">
  <div class="label">Команда</div>
  <div class="value" id="v-cron"><?= htmlspecialchars($cronCmd) ?></div>
  <button onclick="copy('v-cron', this)">Копировать</button>
</div>

<script>
function copy(id, btn) {
  navigator.clipboard.writeText(document.getElementById(id).textContent).then(() => {
    const o = btn.textContent; btn.textContent = '✓ Скопировано'; btn.classList.add('copied');
    setTimeout(() => { btn.textContent = o; btn.classList.remove('copied'); }, 1500);
  });
}
</script>

</body>
</html>
