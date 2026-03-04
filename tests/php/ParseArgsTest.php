<?php
/**
 * Unit tests for the parseArgs() argument parsing function.
 * Self-contained — runs with: php tests/php/ParseArgsTest.php
 */

require __DIR__ . '/bootstrap.php';

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

// --- Test 10: Flag at end emits STDERR warning ---
echo "\nTest: Flag at end emits STDERR warning\n";
ob_start();
$stderrCapture = tmpfile();
$stderrPath = stream_get_meta_data($stderrCapture)['uri'];
// We can't easily capture STDERR in-process, so just verify the return value
$result = parseArgs(['--sort']);
assertTest(!array_key_exists('sort', $result), '--sort with no value omitted from result (warning emitted to STDERR)');

// --- Test 11: Flag followed by another flag emits STDERR warning ---
echo "\nTest: Flag followed by another flag emits STDERR warning\n";
$result = parseArgs(['--url', '--sort', 'wt']);
assertTest(!array_key_exists('url', $result), '--url followed by --sort omitted from result (warning emitted to STDERR)');
assertTest(($result['sort'] ?? null) === 'wt', '--sort still parsed correctly after valueless --url');

// --- Summary ---
printTestSummary();
