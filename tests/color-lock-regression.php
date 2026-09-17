<?php
declare(strict_types=1);
// Exercise the actual color worker's lock preamble in a separate process.
// No production configuration, APIs or sheet access.
if (($argv[1] ?? '') === 'child') {
    define('DATA_ROOT', $argv[2]);
    $LOCK_FILE = DATA_ROOT . '/color-links.lock';
    $source = file_get_contents(__DIR__ . '/../bin/color-links.php');
    $start = strpos($source, '$lock = @fopen($LOCK_FILE');
    $end = strpos($source, 'function logline', $start);
    if ($start === false || $end === false) exit(2);
    eval(substr($source, $start, $end - $start));
    if (!isset($syncLock) || !is_resource($syncLock)) exit(3);
    echo 'READY';
    exit(0);
}
$dir = sys_get_temp_dir() . '/tris-color-lock-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    foreach (['cron.lock', 'color-links.lock', null] as $held) {
        $lock = null;
        if ($held !== null) {
            $lock = fopen($dir . '/' . $held, 'c');
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Fixture lock failed');
        }
        $proc = proc_open([PHP_BINARY, '-n', __FILE__, 'child', $dir],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Could not start child');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($lock !== null) fclose($lock);
        if ($code !== 0 || $err !== '' || $out !== ($held === null ? 'READY' : '')) {
            throw new RuntimeException('Lock regression: ' . ($held ?? 'free') . ': ' . $out . $err);
        }
        echo 'PASS ' . ($held === null ? 'color worker obtains both locks when idle' : "color worker skips when $held is held") . PHP_EOL;
    }
} finally {
    foreach (['cron.lock', 'color-links.lock'] as $file) {
        if (is_file($dir . '/' . $file)) unlink($dir . '/' . $file);
    }
    rmdir($dir);
}
