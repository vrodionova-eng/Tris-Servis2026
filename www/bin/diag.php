<?php
// Diagnostic: check if booking field values are B24 user IDs + get deal full data.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. Are 495, 497 user IDs?
echo "=== user.get for 495, 497 ===\n";
foreach ([495, 497] as $id) {
    try {
        $u = b24wh('user.get', ['ID' => $id]);
        $user = is_array($u) ? ($u[0] ?? $u) : [];
        echo "ID=$id: NAME=" . ($user['NAME'] ?? '?') . " " . ($user['LAST_NAME'] ?? '?') . "\n";
    } catch (Throwable $e) {
        echo "ID=$id ERROR: " . $e->getMessage() . "\n";
    }
}

// 2. Full deal #887 via crm.deal.get (richer than list)
echo "\n=== crm.deal.get(887) booking fields ===\n";
try {
    $deal = b24wh('crm.deal.get', ['id' => 887]);
    foreach (B24_BOOKING_FIELDS as $f) {
        $v = $deal[$f] ?? 'KEY_MISSING';
        echo "$f = " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
