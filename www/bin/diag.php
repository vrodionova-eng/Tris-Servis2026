<?php
// Diagnostic: find resourceTypeIdList for calendar.resource.booking.list.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../env.php';
require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/../api/b24.php';

// 1. calendar.resource.list — with all fields to find typeId
echo "=== calendar.resource.list (raw first item) ===\n";
try {
    $res = b24wh('calendar.resource.list', []);
    $list = is_array($res) ? $res : [];
    echo "  total: " . count($list) . "\n";
    if (!empty($list)) {
        echo "  first item (all fields): " . json_encode($list[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    $list = [];
}

// 2. Try calendar.resource.booking.list with resource IDs as resourceTypeIdList
$employeeIds = [35, 37, 39, 41, 43]; // Тусюк, Козлянко, Муха, Кузавко, Сержанов
$allIds = [20, 22, 29, 31, 33, 35, 37, 39, 41, 43, 45, 47, 49, 51, 73];

echo "\n=== calendar.resource.booking.list (resourceTypeIdList = employee IDs) ===\n";
try {
    $r = b24wh('calendar.resource.booking.list', [
        'filter' => [
            'resourceTypeIdList' => $employeeIds,
            'dateFrom'           => '2026-06-01',
            'dateTo'             => '2026-06-30',
        ],
    ]);
    echo "  raw: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== calendar.resource.booking.list (resourceTypeIdList = ALL resource IDs) ===\n";
try {
    $r = b24wh('calendar.resource.booking.list', [
        'filter' => [
            'resourceTypeIdList' => $allIds,
            'dateFrom'           => '2026-06-01',
            'dateTo'             => '2026-06-30',
        ],
    ]);
    echo "  raw: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 3. Try alternative parameter name spellings
echo "\n=== calendar.resource.booking.list (various param names) ===\n";
$attempts = [
    ['resourceIdList'     => $employeeIds],
    ['RESOURCE_ID'        => $employeeIds],
    ['resourceTypeIdList' => [1]],
    ['resourceTypeIdList' => [2]],
    ['resourceTypeIdList' => [3]],
];
foreach ($attempts as $filter) {
    try {
        $r = b24wh('calendar.resource.booking.list', ['filter' => $filter]);
        echo "  filter=" . json_encode($filter) . " → " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "  filter=" . json_encode($filter) . " → ERROR: " . $e->getMessage() . "\n";
    }
}

// 4. calendar.resource.getFields — understand resource structure
echo "\n=== calendar.resource.getFields ===\n";
try {
    $r = b24wh('calendar.resource.getFields', []);
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
