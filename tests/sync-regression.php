<?php
declare(strict_types=1);
namespace SyncRegression;

// No production configuration, credentials, network or file-state writes.
// Load the actual worker functions into a namespace with in-memory adapters.
const DATA_ROOT = '/test-memory';
const LAST_SYNC_FILE = DATA_ROOT . '/last-sync';
const SHEETS_ID = 'test';
const B24_WEBHOOK_URL = 'https://example.invalid/rest/test/';
date_default_timezone_set('UTC');
foreach (['api/sync.php' => 'function loadTechUsers(', 'bin/process.php' => 'function runJob('] as $file => $marker) {
    $source = file_get_contents(__DIR__ . '/../' . $file);
    $start = strpos($source, $marker);
    if ($start === false) throw new \RuntimeException('Cannot load test subject: ' . $file);
    eval('namespace ' . __NAMESPACE__ . '; use \\RuntimeException; use \\Throwable;' . substr($source, $start));
}

function check(bool $ok, string $message): void {
    if (!$ok) throw new \RuntimeException($message);
}
function logline(string $message): void { $GLOBALS['logs'][] = $message; }
function storeRead(string $path): ?array { return $GLOBALS['state'][$path] ?? null; }
function storeWrite(string $path, array $data): void { $GLOBALS['state'][$path] = $data; }
function b24wh(string $method, array $params = []) {
    $GLOBALS['calls'][] = $method;
    if (($GLOBALS['fail'] ?? '') === $method) throw new \RuntimeException('Simulated API failure');
    switch ($method) {
        case 'user.get': return [['ID' => '65', 'LAST_NAME' => 'Козлянко'], ['ID' => '71', 'LAST_NAME' => 'Юрченков']];
        case 'calendar.event.get': return $GLOBALS['events'][$params['ownerId']] ?? [];
        case 'calendar.event.getbyid': return $GLOBALS['details'][$params['id']] ?? [];
        case 'crm.deal.get': return $GLOBALS['deals'][$params['id']] ?? [];
        default: throw new \RuntimeException('Unexpected API: ' . $method);
    }
}
class GoogleSheets {
    public function __construct(string $id) {}
    public function readHeaderRow(): array { return ['B' => 'Козлянко', 'C' => 'Юрченков']; }
    public function readColumnA(): array { return $GLOBALS['rows']; }
    public function batchUpdate(array $updates): void { $GLOBALS['writes'] = array_merge($GLOBALS['writes'], $updates); }
    public function insertMonthRow(string $label, int $pos): void { $GLOBALS['inserts'][] = ['month', $label, $pos]; }
    public function insertDateRow(string $date, int $pos): void { $GLOBALS['inserts'][] = ['date', $date, $pos]; }
}
function event(string $id, string $deal, string $name = 'Old title'): array {
    return ['ID' => $id, 'NAME' => 'Бронирование: ' . $name, 'EVENT_TYPE' => '#resourcebooking#',
        'DATE_FROM' => '21.09.2026 10:00:00', 'DATE_TO' => '21.09.2026 10:00:00', 'UF_CRM_CAL_EVENT' => ['D_' . $deal]];
}
function resetFixture(): void {
    $GLOBALS['argv'] = ['test'];
    $GLOBALS['state'] = $GLOBALS['writes'] = $GLOBALS['inserts'] = $GLOBALS['calls'] = $GLOBALS['logs'] = $GLOBALS['details'] = [];
    $GLOBALS['fail'] = '';
    $GLOBALS['rows'] = ['dates' => ['21.09.2026' => 2, '22.09.2026' => 3], 'months' => ['2026-09' => 1]];
    $GLOBALS['events'] = ['65' => [event('889', '839', '- КП - БРОДНИЦА'), event('903', '1175')], '71' => [event('891', '839')]];
    $GLOBALS['deals'] = ['839' => ['ID' => '839', 'TITLE' => 'БРОДНИЦА', 'STAGE_SEMANTIC_ID' => 'P'],
        '1175' => ['ID' => '1175', 'TITLE' => 'БРЕСТВОДОКАНАЛ', 'STAGE_SEMANTIC_ID' => 'P']];
}
function assigned(): array { return $GLOBALS['state'][DATA_ROOT . '/cron-cells.php']; }
function expectAbort(): void {
    $before = $GLOBALS['state'];
    $caught = false;
    try { runJob(); } catch (\RuntimeException $e) { $caught = true; }
    check($caught, 'Expected abort');
    check($GLOBALS['writes'] === [] && $GLOBALS['inserts'] === [] && $GLOBALS['state'] === $before, 'Failure changed sheet or state');
}
$tests = [
    'renamed booking, two deals in one cell, two employees' => function () {
        runJob(); $a = assigned();
        check(count($a['21.09.2026|Козлянко']) === 2, 'Lost second deal');
        check($a['21.09.2026|Козлянко'][839]['title'] === 'БРОДНИЦА', 'Old title used');
        check(isset($a['21.09.2026|Юрченков'][839]), 'Lost second employee');
        check(substr_count($GLOBALS['writes'][0]['text'], "\n") === 1, 'Cell must contain two lines');
        check(count(array_filter($GLOBALS['calls'], fn($m) => $m === 'crm.deal.get')) === 2, 'Deal fetched repeatedly');
    },
    'identical deal titles keep distinct links' => function () {
        $GLOBALS['deals'][1175]['TITLE'] = 'БРОДНИЦА'; runJob();
        check(count(assigned()['21.09.2026|Козлянко']) === 2, 'Duplicate titles collapsed');
        $runs = $GLOBALS['writes'][0]['runs'];
        check($runs[0]['format']['link']['uri'] !== $runs[1]['format']['link']['uri'], 'Links collapsed');
    },
    'unchanged cells are not rewritten (colors retained)' => function () {
        runJob(); $GLOBALS['writes'] = []; runJob(); check($GLOBALS['writes'] === [], 'Unchanged cells rewritten');
    },
    'rename updates only affected cells, unrelated cell unchanged' => function () {
        $GLOBALS['events']['71'] = [event('891', '1175')]; runJob(); $GLOBALS['writes'] = [];
        $GLOBALS['deals'][839]['TITLE'] = 'Новое название'; runJob();
        check(count($GLOBALS['writes']) === 1 && $GLOBALS['writes'][0]['cellRef'] === 'B2', 'Unrelated cell rewritten');
        check(isset(assigned()['21.09.2026|Козлянко'][839]), 'Rename removed booking');
    },
    'actual deletion removes only deleted booking' => function () {
        runJob(); $GLOBALS['writes'] = []; $GLOBALS['events']['65'] = [event('903', '1175')]; runJob();
        check(!isset(assigned()['21.09.2026|Козлянко'][839]) && isset(assigned()['21.09.2026|Козлянко'][1175]), 'Deletion incorrect');
        check(isset(assigned()['21.09.2026|Юрченков'][839]), 'Deleted other employee booking');
    },
    'empty calendar clears stale cells' => function () {
        runJob(); $GLOBALS['writes'] = []; $GLOBALS['events'] = []; runJob();
        check(assigned() === [] && count($GLOBALS['writes']) === 2, 'Stale cells not cleared');
        foreach ($GLOBALS['writes'] as $w) check($w['text'] === '', 'Stale cell not empty');
    },
    'won deals retain bookings and links' => function () {
        $GLOBALS['deals'][839]['STAGE_SEMANTIC_ID'] = 'S'; runJob();
        check(isset(assigned()['21.09.2026|Козлянко'][839], assigned()['21.09.2026|Юрченков'][839]), 'Closed booking lost');
    },
    'lost deals retain bookings after rename' => function () {
        runJob(); $GLOBALS['deals'][839]['STAGE_SEMANTIC_ID'] = 'F';
        $GLOBALS['deals'][839]['TITLE'] = 'Closed renamed'; runJob();
        check(assigned()['21.09.2026|Козлянко'][839]['title'] === 'Closed renamed', 'Lost deal removed');
    },
    'missing list bindings are retrieved from event details' => function () {
        $GLOBALS['details'][889] = $GLOBALS['events']['65'][0]; unset($GLOBALS['events']['65'][0]['UF_CRM_CAL_EVENT']);
        runJob(); check(isset(assigned()['21.09.2026|Козлянко'][839]), 'Detail fallback failed');
    },
    'missing CRM binding aborts before writes' => function () {
        $GLOBALS['events']['65'][0]['UF_CRM_CAL_EVENT'] = []; expectAbort();
    },
    'ambiguous CRM binding aborts before writes' => function () {
        $GLOBALS['events']['65'][0]['UF_CRM_CAL_EVENT'] = ['D_839', 'D_1175']; expectAbort();
    },
    'calendar failure preserves previous state' => function () {
        runJob(); $GLOBALS['writes'] = []; $GLOBALS['fail'] = 'calendar.event.get'; expectAbort();
    },
    'deal read failure preserves previous state' => function () {
        runJob(); $GLOBALS['writes'] = []; $GLOBALS['fail'] = 'crm.deal.get'; expectAbort();
    },
    'multiday booking and timing for coloring' => function () {
        $GLOBALS['events']['65'][0]['DATE_TO'] = '22.09.2026 12:00:00'; runJob();
        check(isset(assigned()['22.09.2026|Козлянко'][839]), 'Second day missing');
        $times = $GLOBALS['state'][DATA_ROOT . '/deal-booking-times.php'];
        check($times[839]['2026-09-21'] === strtotime('2026-09-21 10:00:00'), 'Color timing changed');
    },
    'missing date row inserted' => function () {
        unset($GLOBALS['rows']['dates']['21.09.2026']); runJob();
        check($GLOBALS['inserts'] === [['date', '21.09.2026', 3]], 'Date row insertion failed');
    },
    'dry run makes no sheet or sync-state writes' => function () {
        $GLOBALS['argv'][] = '--dry-run'; runJob();
        check($GLOBALS['writes'] === [] && $GLOBALS['inserts'] === [] && $GLOBALS['state'] === [], 'Dry run wrote data');
    },
    'dry run reports deleted and changed cells without applying them' => function () {
        runJob(); $before = $GLOBALS['state']; $GLOBALS['writes'] = []; $GLOBALS['logs'] = [];
        $GLOBALS['events']['71'] = []; $GLOBALS['deals'][839]['TITLE'] = 'Renamed';
        $GLOBALS['argv'][] = '--dry-run'; runJob();
        check($GLOBALS['state'] === $before && $GLOBALS['writes'] === [], 'Preview applied changes');
        check(strpos(implode("\n", $GLOBALS['logs']), 'write=1, clear=1') !== false, 'Incorrect preview counts');
    },
];
$failed = 0;
foreach ($tests as $name => $test) {
    resetFixture();
    try { $test(); echo "PASS $name\n"; }
    catch (\Throwable $e) { $failed++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}
echo count($tests) . " tests, $failed failures\n";
exit($failed ? 1 : 0);
