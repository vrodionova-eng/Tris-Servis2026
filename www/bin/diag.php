<?php
// Diagnostic: find a deal with booking fields filled, show raw data.
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

$select = array_merge(['ID', 'TITLE'], B24_BOOKING_FIELDS);
$found  = null;
$start  = 0;

while ($found === null && $start < 500) {
    $r     = b24wh('crm.item.list', [
        'entityTypeId' => 2,
        'order'        => ['ID' => 'DESC'],
        'select'       => $select,
        'start'        => $start,
    ]);
    $items = $r['items'] ?? [];
    if (empty($items)) break;

    foreach ($items as $deal) {
        foreach (B24_BOOKING_FIELDS as $f) {
            $v = $deal[$f] ?? null;
            if ($v !== null && $v !== '' && $v !== []) {
                $found = $deal;
                break 2;
            }
        }
    }
    $start += 50;
    echo "Checked " . ($start) . " deals, not found yet...\n";
}

if ($found === null) {
    echo "No deal with booking fields found in first 500 deals.\n";
    exit(1);
}

echo "Deal: #{$found['id']} {$found['title']}\n\n";
foreach (B24_BOOKING_FIELDS as $f) {
    $v = $found[$f] ?? null;
    echo "$f = " . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
}
