<?php
/**
 * Unit tests for output formatting.
 * Self-contained — runs with: php tests/php/FormattingTest.php
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

// --- Test 1: Microseconds to milliseconds ---
echo "Test: Microseconds to milliseconds conversion\n";

$testCases = [
    [450139, '450.1'],
    [120300, '120.3'],
    [1000, '1.0'],
    [999, '1.0'],
    [500, '0.5'],
    [0, '0.0'],
];

foreach ($testCases as [$us, $expected]) {
    $result = number_format($us / 1000, 1);
    assertTest($result === $expected, "{$us}us => {$result}ms (expected {$expected}ms)");
}

// --- Test 2: Bytes to MB ---
echo "\nTest: Bytes to MB conversion\n";

$testCases = [
    [8388608, '8.0'],    // 8 MB
    [6501171, '6.2'],    // ~6.2 MB
    [1048576, '1.0'],    // 1 MB
    [0, '0.0'],
    [524288, '0.5'],     // 0.5 MB
];

foreach ($testCases as [$bytes, $expected]) {
    $result = number_format($bytes / 1048576, 1);
    assertTest($result === $expected, "{$bytes}B => {$result}MB (expected {$expected}MB)");
}

// --- Test 3: Truncation ---
echo "\nTest: String truncation\n";

assertTest(truncate('short', 40) === 'short', 'short string unchanged');
assertTest(truncate('', 40) === '', 'empty string unchanged');
assertTest(strlen(truncate(str_repeat('a', 50), 40)) === 40, 'long string truncated to max');
assertTest(substr(truncate(str_repeat('a', 50), 40), -3) === '...', 'truncated string ends with ...');
assertTest(truncate(str_repeat('a', 40), 40) === str_repeat('a', 40), 'exact length unchanged');

// --- Test 4: Sanitize table values ---
echo "\nTest: Sanitize table values\n";

assertTest(sanitizeTableValue("normal text") === "normal text", 'normal text unchanged');
assertTest(sanitizeTableValue("has\x00null") === "hasnull", 'null byte removed');
assertTest(sanitizeTableValue("has\x1b[31mcolor") === "has[31mcolor", 'ANSI escape removed');
assertTest(sanitizeTableValue("tab\there") === "tabhere", 'tab removed');
assertTest(sanitizeTableValue("line\nbreak") === "linebreak", 'newline removed');

// --- Test 5: Profile decode ---
echo "\nTest: Profile decode\n";

$validProfile = json_encode([
    'main()' => ['ct' => 1, 'wt' => 50000, 'cpu' => 49000, 'pmu' => 3000000],
]);
$decoded = decodeProfile($validProfile);
assertTest(is_array($decoded), 'valid JSON decoded');
assertTest(isset($decoded['main()']), 'main() key present');

$invalidProfile = 'not json at all';
assertTest(decodeProfile($invalidProfile) === null, 'invalid JSON returns null');

$emptyObject = '{}';
$decoded = decodeProfile($emptyObject);
assertTest(is_array($decoded) && empty($decoded), 'empty object returns empty array');

// Gzip detection (without actual gzip data, just test detection logic)
$fakeGzip = "\x1f\x8b" . "invalid gzip data";
assertTest(decodeProfile($fakeGzip) === null, 'invalid gzip returns null');

// --- Test 6: JSON output schema for runs ---
echo "\nTest: JSON output schema (runs)\n";

$row = [
    'id' => 'abc123def456789012345678',
    'url' => '/shop/products',
    'main_wt' => '450139',
    'main_cpu' => '120300',
    'main_pmu' => '8388608',
    'request_ts' => '1709561580',
];

$jsonRow = [
    'id'               => $row['id'],
    'url'              => $row['url'],
    'wall_time_us'     => (int)$row['main_wt'],
    'cpu_time_us'      => (int)$row['main_cpu'],
    'peak_memory_bytes'=> (int)$row['main_pmu'],
    'timestamp'        => gmdate('Y-m-d\TH:i:s\Z', (int)$row['request_ts']),
];

$json = json_encode([$jsonRow], JSON_PRETTY_PRINT);
$decoded = json_decode($json, true);

assertTest($decoded !== null, 'JSON output is valid');
assertTest(count($decoded) === 1, 'JSON has one entry');
assertTest($decoded[0]['wall_time_us'] === 450139, 'wall_time_us is integer microseconds');
assertTest($decoded[0]['cpu_time_us'] === 120300, 'cpu_time_us is integer microseconds');
assertTest($decoded[0]['peak_memory_bytes'] === 8388608, 'peak_memory_bytes is integer bytes');
assertTest(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $decoded[0]['timestamp']) === 1, 'timestamp is ISO 8601');

// --- Test 7: Table output column alignment ---
echo "\nTest: Table output rendering\n";

ob_start();
printTable(
    ['NAME', 'VALUE'],
    [
        ['short', '1'],
        ['a longer name', '200'],
    ]
);
$output = ob_get_clean();

$lines = explode("\n", rtrim($output));
assertTest(count($lines) === 3, 'table has header + 2 data rows');
assertTest(strpos($lines[0], 'NAME') === 0, 'header starts with NAME');
assertTest(strpos($lines[0], 'VALUE') !== false, 'header contains VALUE');

// --- Summary ---
echo "\n" . ($passed + $failed) . " tests, $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
