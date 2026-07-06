<?php
/**
 * Unit tests for the parseArgs() argument parsing function.
 * Self-contained — runs with: php tests/php/ParseArgsTest.php
 */

require __DIR__ . '/bootstrap.php';

// All flags valid across both subcommands, for tests that aren't allowlist-specific
$ALL_FLAGS = ['limit', 'url', 'sort', 'format', 'run-id'];

// --- Test 1: Standard --flag value pair ---
echo "Test: Standard --flag value pair\n";
$result = parseArgs(['--sort', 'wt'], $ALL_FLAGS);
assertTest($result['opts'] === ['sort' => 'wt'], 'parseArgs([--sort, wt]) returns [sort => wt]');
assertTest($result['errors'] === [], 'no errors for valid flag');

// --- Test 2: --flag=value syntax ---
echo "\nTest: --flag=value syntax\n";
$result = parseArgs(['--sort=wt'], $ALL_FLAGS);
assertTest($result['opts'] === ['sort' => 'wt'], 'parseArgs([--sort=wt]) returns [sort => wt]');
assertTest($result['errors'] === [], 'no errors for = syntax');

$result = parseArgs(['--limit=5', '--sort', 'cpu'], $ALL_FLAGS);
assertTest($result['opts'] === ['limit' => '5', 'sort' => 'cpu'], 'mixed = and space syntax both parse');

$result = parseArgs(['--url='], $ALL_FLAGS);
assertTest($result['opts'] === ['url' => ''], '--url= yields empty-string value');

$result = parseArgs(['--url=a=b'], $ALL_FLAGS);
assertTest($result['opts'] === ['url' => 'a=b'], 'only first = splits; value keeps later = chars');

// --- Test 3: Multiple flags ---
echo "\nTest: Multiple flags\n";
$result = parseArgs(['--sort', 'wt', '--limit', '5'], $ALL_FLAGS);
assertTest($result['opts'] === ['sort' => 'wt', 'limit' => '5'], 'parseArgs([--sort, wt, --limit, 5]) returns both flags');

// --- Test 4: --help boolean ---
echo "\nTest: --help boolean\n";
$result = parseArgs(['--help'], $ALL_FLAGS);
assertTest($result['opts'] === ['help' => true], 'parseArgs([--help]) returns [help => true]');
assertTest($result['errors'] === [], '--help produces no errors');

$result = parseArgs(['--help', '--sort', 'wt'], $ALL_FLAGS);
assertTest($result['opts'] === ['help' => true, 'sort' => 'wt'], 'parseArgs([--help, --sort, wt]) returns help and sort');

// --- Test 5: Flag with no value is an error ---
echo "\nTest: Flag with no value is an error\n";
$result = parseArgs(['--sort'], $ALL_FLAGS);
assertTest(!array_key_exists('sort', $result['opts']), '--sort with no value is not in opts');
assertTest(count($result['errors']) === 1, 'exactly one error collected');
assertTest(strpos($result['errors'][0], "requires a value") !== false, 'error says a value is required');

$result = parseArgs(['--url', '--sort', 'wt'], $ALL_FLAGS);
assertTest(!array_key_exists('url', $result['opts']), '--url followed by --sort is not in opts');
assertTest(($result['opts']['sort'] ?? null) === 'wt', '--sort still parsed after valueless --url');
assertTest(count($result['errors']) === 1, 'valueless --url collected as error');

// --- Test 6: Unknown flag is an error ---
echo "\nTest: Unknown flag is an error\n";
$result = parseArgs(['--bogus', '5'], $ALL_FLAGS);
assertTest($result['opts'] === [], 'unknown flag not in opts');
assertTest(count($result['errors']) === 1, 'unknown flag is one error (its value consumed, not a second error)');
assertTest(strpos($result['errors'][0], "Unknown flag '--bogus'") !== false, 'error names the unknown flag');

$result = parseArgs(['--bogus', '5', '--sort', 'wt'], $ALL_FLAGS);
assertTest(($result['opts']['sort'] ?? null) === 'wt', 'parsing continues after unknown flag + value');

$result = parseArgs(['--bogus=5', '--sort', 'wt'], $ALL_FLAGS);
assertTest(($result['opts']['sort'] ?? null) === 'wt', 'parsing continues after unknown --flag=value');
assertTest(count($result['errors']) === 1, 'unknown --flag=value is one error');

// --- Test 7: Allowlist is per-subcommand ---
echo "\nTest: Allowlist is per-subcommand\n";
$result = parseArgs(['--run-id', 'abc'], FLAGS_RUNS);
assertTest(count($result['errors']) === 1, '--run-id rejected for runs');
$result = parseArgs(['--run-id', 'abc'], FLAGS_TOP_FUNCTIONS);
assertTest($result['errors'] === [], '--run-id accepted for top-functions');
$result = parseArgs(['--url', '/x'], FLAGS_TOP_FUNCTIONS);
assertTest(count($result['errors']) === 1, '--url rejected for top-functions');
$result = parseArgs(['--function', 'PDO::query'], FLAGS_CALLERS);
assertTest($result['errors'] === [] && $result['opts']['function'] === 'PDO::query', '--function accepted for callers');
$result = parseArgs(['--function', 'PDO::query'], FLAGS_RUNS);
assertTest(count($result['errors']) === 1, '--function rejected for runs');

// --- Test 8: Duplicate flags (last wins) ---
echo "\nTest: Duplicate flags (last wins)\n";
$result = parseArgs(['--sort', 'wt', '--sort', 'cpu'], $ALL_FLAGS);
assertTest($result['opts'] === ['sort' => 'cpu'], 'parseArgs with duplicate --sort keeps last value (cpu)');

// --- Test 9: Empty args ---
echo "\nTest: Empty args\n";
$result = parseArgs([], $ALL_FLAGS);
assertTest($result['opts'] === [] && $result['errors'] === [], 'parseArgs([]) returns empty opts and errors');

// --- Test 10: Bare arguments are errors ---
echo "\nTest: Bare arguments are errors\n";
$result = parseArgs(['runs', '--sort', 'wt'], $ALL_FLAGS);
assertTest(($result['opts']['sort'] ?? null) === 'wt', 'flags still parsed around bare argument');
assertTest(count($result['errors']) === 1, 'bare argument collected as error');
assertTest(strpos($result['errors'][0], "Unexpected argument 'runs'") !== false, 'error names the bare argument');

$result = parseArgs(['--'], $ALL_FLAGS);
assertTest(count($result['errors']) === 1, "bare '--' is an error");

// --- Test 11: Negative-looking values are consumed as values ---
echo "\nTest: Negative-looking values are consumed as values\n";
$result = parseArgs(['--limit', '-5'], $ALL_FLAGS);
assertTest($result['opts'] === ['limit' => '-5'], '-5 consumed as value for --limit (range check happens later)');

// --- Summary ---
printTestSummary();
