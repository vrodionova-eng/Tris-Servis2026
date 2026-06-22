<?php
// Diagnostic: find deal with booking fields via crm.deal.list.
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// crm.deal.list (classic API) — supports UF_ fields
$select = array_merge(['ID', 'TITLE', 'CATEGORY_ID'], B24_BOOKING_FIELDS);
$found  = null;
$start  = 0;

while ($found === null && $start < 2000) {
    $items = b24wh('crm.deal.list', [
        'order'  => ['ID' => 'DESC'],
        'select' => $select,
        'start'  => $start,
    ]);
    if (!is_array($items) || empty($items)) {
        echo "No more deals at start=$start\n";
        break;
    }

    // Show keys of first deal once
    if ($start === 0) {
        echo "Keys in first deal: " . implode(', ', array_keys($items[0])) . "\n\n";
    }

    foreach ($items as $deal) {
        foreach (B24_BOOKING_FIELDS as $f) {
            $raw = $deal[$f] ?? null;
            if ($raw !== null && $raw !== '' && $raw !== [] && $raw !== 'null' && $raw !== false) {
                $found = $deal;
                break 2;
            }
        }
    }
    $start += 50;
    echo "Checked $start deals...\n";
}

if ($found === null) {
    // Show sample deal raw values
    echo "\nNot found. Sample last deal raw:\n";
    if (!empty($items) && is_array($items)) {
        $s = $items[0];
        foreach (B24_BOOKING_FIELDS as $f) {
            echo "$f = " . var_export($s[$f] ?? 'KEY_MISSING', true) . "\n";
        }
    }
    exit(1);
}

echo "\n=== FOUND: Deal #{$found['ID']} {$found['TITLE']} ===\n";
echo "CATEGORY_ID = " . ($found['CATEGORY_ID'] ?? 'n/a') . "\n\n";
foreach (B24_BOOKING_FIELDS as $f) {
    $v = $found[$f] ?? null;
    echo "$f =\n" . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
}
