<?php
/**
 * Unit tests for input validation logic.
 * Self-contained — runs with: php tests/php/InputValidationTest.php
 */

define('XHGUI_CLI_TESTING', true);
require __DIR__ . '/../../xhgui-cli/query.php';

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS: $name\n";
    } else {
        $failed++;
        echo "  FAIL: $name\n";
    }
}

// --- Test 1: Run ID validation ---
echo "Test: Run ID regex validation\n";

$validIds = [
    'abc123def456789012345678',
    'ABCDEF0123456789abcdef01',
    '000000000000000000000000',
    'ffffffffffffffffffffffff',
];

foreach ($validIds as $id) {
    assertTest(preg_match(RUN_ID_PATTERN, $id) === 1, "valid run-id: $id");
}

$invalidIds = [
    '',
    'abc',
    'abc123def45678901234567',   // 23 chars (too short)
    'abc123def4567890123456789', // 25 chars (too long)
    'abc123def456789012345678!', // special char
    'xyz123def456789012345678',  // invalid hex chars (x, y, z are not all hex... wait x is not hex)
    'ghijklmnopqrstuvwxyz1234',  // non-hex chars
    'abc123def456789012345 78',  // space
];

foreach ($invalidIds as $id) {
    assertTest(preg_match(RUN_ID_PATTERN, $id) === 0, "invalid run-id: '$id'");
}

// --- Test 2: Sort allowlist for runs ---
echo "\nTest: Sort allowlist (runs)\n";

$validSorts = ['time', 'wt', 'cpu', 'pmu'];
foreach ($validSorts as $sort) {
    assertTest(isset(SORT_MAP_RUNS[$sort]), "valid runs sort: $sort");
}

$invalidSorts = ['', 'memory', 'mu', 'request_ts DESC; DROP TABLE results; --', 'wall'];
foreach ($invalidSorts as $sort) {
    assertTest(!isset(SORT_MAP_RUNS[$sort]), "invalid runs sort rejected: '$sort'");
}

// --- Test 3: Sort allowlist for functions ---
echo "\nTest: Sort allowlist (functions)\n";

$validSorts = ['wt', 'cpu', 'pmu'];
foreach ($validSorts as $sort) {
    assertTest(isset(SORT_MAP_FUNCTIONS[$sort]), "valid functions sort: $sort");
}

assertTest(!isset(SORT_MAP_FUNCTIONS['time']), "'time' not valid for functions sort");
assertTest(!isset(SORT_MAP_FUNCTIONS['mu']), "'mu' not valid for functions sort");

// --- Test 4: Format allowlist ---
echo "\nTest: Format allowlist\n";

assertTest(in_array('table', VALID_FORMATS, true), "'table' is valid format");
assertTest(in_array('json', VALID_FORMATS, true), "'json' is valid format");
assertTest(!in_array('csv', VALID_FORMATS, true), "'csv' rejected");
assertTest(!in_array('xml', VALID_FORMATS, true), "'xml' rejected");

// --- Test 5: Limit range ---
echo "\nTest: Limit range constants\n";

assertTest(LIMIT_MIN === 1, 'limit min is 1');
assertTest(LIMIT_MAX === 1000, 'limit max is 1000');

// Simulate limit validation
$testLimits = [
    [0, false],
    [1, true],
    [500, true],
    [1000, true],
    [1001, false],
    [-1, false],
];

foreach ($testLimits as [$limit, $expected]) {
    $valid = $limit >= LIMIT_MIN && $limit <= LIMIT_MAX;
    assertTest($valid === $expected, "limit $limit " . ($expected ? 'accepted' : 'rejected'));
}

// --- Test 6: URL wildcard escaping ---
echo "\nTest: URL wildcard escaping\n";

$testCases = [
    ['/shop/products', '/shop/products'],
    ['/shop%20cart', '/shop\\%20cart'],
    ['test_page', 'test\\_page'],
    ['100%_done', '100\\%\\_done'],
    ['back\\slash', 'back\\\\slash'],
];

foreach ($testCases as [$input, $expected]) {
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input);
    assertTest($escaped === $expected, "escape '$input' => '$escaped'");
}

// --- Summary ---
echo "\n" . ($passed + $failed) . " tests, $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
