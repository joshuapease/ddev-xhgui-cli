<?php
/**
 * Shared test harness for self-contained PHP unit tests.
 * Usage: require __DIR__ . '/bootstrap.php'; at the top of each test file.
 */

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

function printTestSummary(): void
{
    global $passed, $failed;
    echo "\n" . ($passed + $failed) . " tests, $passed passed, $failed failed.\n";
    exit($failed > 0 ? 1 : 0);
}

define('XHGUI_CLI_TESTING', true);
require __DIR__ . '/../../xhgui-cli/query.php';
