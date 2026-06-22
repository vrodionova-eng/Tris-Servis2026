<?php
// Diagnostic: find a deal with booking fields filled (no filters).
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

$select = array_merge(['ID', 'TITLE', 'CATEGORY_ID'], B24_BOOKING_FIELDS);
$found  = null;
$start  = 0;

while ($found === null && $start < 2000) {
    $r     = b24wh('crm.item.list', [
        'entityTypeId' => 2,
        'order'        => ['ID' => 'DESC'],
        'select'       => $select,
        'start'        => $start,
    ]);
    $items = $r['items'] ?? [];
    if (empty($items)) { echo "No more deals at start=$start\n"; break; }

    foreach ($items as $deal) {
        foreach (B24_BOOKING_FIELDS as $f) {
            $raw = $deal[$f] ?? null;
            // non-empty: not null, not empty string, not empty array
            if ($raw !== null && $raw !== '' && $raw !== [] && $raw !== 'null') {
                $found = $deal;
                break 2;
            }
        }
    }
    $start += 50;
    echo "Checked $start deals...\n";
}

if ($found === null) {
    echo "No deal with booking fields found in first 2000 deals.\n";

    // Show sample of last batch to see what field values look like
    if (!empty($items)) {
        $sample = $items[0];
        echo "\nSample deal #{$sample['id']} raw booking fields:\n";
        foreach (B24_BOOKING_FIELDS as $f) {
            echo "$f = " . var_export($sample[$f] ?? 'KEY_MISSING', true) . "\n";
        }
    }
    exit(1);
}

echo "\n=== FOUND: Deal #{$found['id']} {$found['title']} ===\n";
echo "categoryId = " . ($found['categoryId'] ?? 'n/a') . "\n\n";
foreach (B24_BOOKING_FIELDS as $f) {
    $v = $found[$f] ?? null;
    echo "$f =\n" . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
}
