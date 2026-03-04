<?php
/**
 * Unit tests for the parseArgs() argument parsing function.
 * Self-contained — runs with: php tests/php/ParseArgsTest.php
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

// --- Test 1: Standard --flag value pair ---
echo "Test: Standard --flag value pair\n";
$result = parseArgs(['--sort', 'wt']);
assertTest($result === ['sort' => 'wt'], 'parseArgs([--sort, wt]) returns [sort => wt]');

// --- Test 2: Multiple flags ---
echo "\nTest: Multiple flags\n";
$result = parseArgs(['--sort', 'wt', '--limit', '5']);
assertTest($result === ['sort' => 'wt', 'limit' => '5'], 'parseArgs([--sort, wt, --limit, 5]) returns both flags');

// --- Test 3: --help boolean ---
echo "\nTest: --help boolean\n";
$result = parseArgs(['--help']);
assertTest($result === ['help' => true], 'parseArgs([--help]) returns [help => true]');

// --- Test 4: --help mixed with other flags ---
echo "\nTest: --help mixed with other flags\n";
$result = parseArgs(['--help', '--sort', 'wt']);
assertTest($result === ['help' => true, 'sort' => 'wt'], 'parseArgs([--help, --sort, wt]) returns help and sort');

// --- Test 5: Flag at end of argv with no value ---
echo "\nTest: Flag at end of argv with no value\n";
$result = parseArgs(['--sort']);
assertTest(!array_key_exists('sort', $result), '--sort with no value is not in result');

// --- Test 6: Duplicate flags (last wins) ---
echo "\nTest: Duplicate flags (last wins)\n";
$result = parseArgs(['--sort', 'wt', '--sort', 'cpu']);
assertTest($result === ['sort' => 'cpu'], 'parseArgs with duplicate --sort keeps last value (cpu)');

// --- Test 7: Flag followed by another flag ---
echo "\nTest: Flag followed by another flag\n";
$result = parseArgs(['--url', '--sort', 'wt']);
assertTest(!array_key_exists('url', $result), '--url followed by --sort is not in result');
assertTest(($result['sort'] ?? null) === 'wt', '--sort is wt when preceded by valueless --url');

// --- Test 8: Empty args ---
echo "\nTest: Empty args\n";
$result = parseArgs([]);
assertTest($result === [], 'parseArgs([]) returns empty array');

// --- Test 9: Non-flag arguments ignored ---
echo "\nTest: Non-flag arguments ignored\n";
$result = parseArgs(['runs', '--sort', 'wt']);
assertTest($result === ['sort' => 'wt'], 'non-flag argument "runs" is ignored');

// --- Summary ---
echo "\n" . ($passed + $failed) . " tests, $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
