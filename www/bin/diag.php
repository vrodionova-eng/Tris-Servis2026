<?php
// Diagnostic: find a deal with booking fields filled in target pipelines.
// Usage: php /var/www/Tris-Servis2026/bin/diag.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. List deal categories (pipelines)
$cats = b24wh('crm.dealcategory.list', []);
$categories = $cats ?? [];
echo "=== Pipelines ===\n";
$targetIds = [];
foreach ($categories as $cat) {
    $id   = (string)($cat['ID'] ?? $cat['id'] ?? '');
    $name = (string)($cat['NAME'] ?? $cat['name'] ?? '');
    $mark = '';
    if (mb_stripos($name, 'Серв') !== false || mb_stripos($name, 'Планов') !== false) {
        $targetIds[] = $id;
        $mark = ' <-- TARGET';
    }
    echo "  [$id] $name$mark\n";
}
echo "\nTarget category IDs: " . (empty($targetIds) ? 'none found, searching all' : implode(', ', $targetIds)) . "\n\n";

// 2. Search deals with booking fields
$select = array_merge(['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_SEMANTIC_ID'], B24_BOOKING_FIELDS);
$filter = ['STAGE_SEMANTIC_ID' => 'P']; // active stages only
if (!empty($targetIds)) {
    $filter['CATEGORY_ID'] = $targetIds;
}

$found = null;
$start = 0;

while ($found === null && $start < 1000) {
    $r     = b24wh('crm.item.list', [
        'entityTypeId' => 2,
        'order'        => ['ID' => 'DESC'],
        'filter'       => $filter,
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
    echo "Checked $start deals...\n";
}

if ($found === null) {
    echo "No deal with booking fields found.\n";
    exit(1);
}

echo "\n=== Deal #{$found['id']} {$found['title']} ===\n";
echo "Category: {$found['categoryId']}, Stage semantic: {$found['stageSemanticId']}\n\n";
foreach (B24_BOOKING_FIELDS as $f) {
    $v = $found[$f] ?? null;
    echo "$f =\n" . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
}
