<?php
/**
 * Unit tests for the exclusive time calculation algorithm.
 * Self-contained — runs with: php tests/php/ExclusiveTimeTest.php
 */

require __DIR__ . '/bootstrap.php';

// --- Test 1: Basic exclusive time calculation ---
echo "Test: Basic exclusive time\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 100000, 'cpu' => 80000, 'pmu' => 5000000],
    'main()==>foo()' => ['ct' => 3, 'wt' => 60000, 'cpu' => 50000, 'pmu' => 3000000],
    'main()==>bar()' => ['ct' => 2, 'wt' => 20000, 'cpu' => 15000, 'pmu' => 1000000],
    'foo()==>baz()' => ['ct' => 5, 'wt' => 10000, 'cpu' => 8000, 'pmu' => 500000],
];

$result = computeExclusiveTimes($profile);
$byFunc = [];
foreach ($result as $f) {
    $byFunc[$f['function']] = $f;
}

// main() inclusive = 100000, children = foo(60000) + bar(20000) = 80000, exclusive = 20000
assertTest($byFunc['main()']['inclusive_wt'] === 100000, 'main() inclusive wt');
assertTest($byFunc['main()']['exclusive_wt'] === 20000, 'main() exclusive wt = 100000 - 80000');

// foo() inclusive = 60000, children = baz(10000), exclusive = 50000
assertTest($byFunc['foo()']['inclusive_wt'] === 60000, 'foo() inclusive wt');
assertTest($byFunc['foo()']['exclusive_wt'] === 50000, 'foo() exclusive wt = 60000 - 10000');
assertTest($byFunc['foo()']['call_count'] === 3, 'foo() call count');

// bar() inclusive = 20000, no children, exclusive = 20000
assertTest($byFunc['bar()']['exclusive_wt'] === 20000, 'bar() exclusive wt = inclusive (leaf)');

// baz() inclusive = 10000, no children, exclusive = 10000
assertTest($byFunc['baz()']['exclusive_wt'] === 10000, 'baz() exclusive wt = inclusive (leaf)');

// --- Test 2: Single function (main() only) ---
echo "\nTest: Single function (main only)\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 50000, 'cpu' => 49000, 'pmu' => 3000000],
];

$result = computeExclusiveTimes($profile);
assertTest(count($result) === 1, 'single function count');
assertTest($result[0]['function'] === 'main()', 'single function is main()');
assertTest($result[0]['exclusive_wt'] === 50000, 'main() exclusive = inclusive when alone');

// --- Test 3: Negative exclusive floors to 0 ---
echo "\nTest: Negative exclusive floors to 0\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 50000, 'cpu' => 40000, 'pmu' => 2000000],
    // Children's sum exceeds parent's inclusive (XHProf shared-callee limitation)
    'main()==>foo()' => ['ct' => 1, 'wt' => 30000, 'cpu' => 25000, 'pmu' => 1500000],
    'main()==>bar()' => ['ct' => 1, 'wt' => 30000, 'cpu' => 25000, 'pmu' => 1500000],
];

$result = computeExclusiveTimes($profile);
$byFunc = [];
foreach ($result as $f) {
    $byFunc[$f['function']] = $f;
}

assertTest($byFunc['main()']['exclusive_wt'] === 0, 'negative exclusive floors to 0');
assertTest($byFunc['main()']['exclusive_cpu'] === 0, 'negative CPU exclusive floors to 0');

// --- Test 4: Function appears as both caller and callee ---
echo "\nTest: Function as both caller and callee\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 100000, 'cpu' => 90000, 'pmu' => 5000000],
    'main()==>foo()' => ['ct' => 2, 'wt' => 80000, 'cpu' => 70000, 'pmu' => 4000000],
    'foo()==>bar()' => ['ct' => 3, 'wt' => 30000, 'cpu' => 25000, 'pmu' => 1000000],
    'bar()==>foo()' => ['ct' => 1, 'wt' => 10000, 'cpu' => 8000, 'pmu' => 500000],
];

$result = computeExclusiveTimes($profile);
$byFunc = [];
foreach ($result as $f) {
    $byFunc[$f['function']] = $f;
}

// foo() inclusive = 80000 (from main) + 10000 (from bar) = 90000
// foo() children = bar(30000), exclusive = 90000 - 30000 = 60000
assertTest($byFunc['foo()']['inclusive_wt'] === 90000, 'foo() inclusive wt sums across callers');
assertTest($byFunc['foo()']['exclusive_wt'] === 60000, 'foo() exclusive wt with recursive call');

// --- Test 5: Missing metric keys default to 0 ---
echo "\nTest: Missing metric keys\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 50000],
    'main()==>foo()' => ['ct' => 2, 'wt' => 20000],
];

$result = computeExclusiveTimes($profile);
$byFunc = [];
foreach ($result as $f) {
    $byFunc[$f['function']] = $f;
}

assertTest($byFunc['main()']['inclusive_cpu'] === 0, 'missing cpu defaults to 0');
assertTest($byFunc['main()']['inclusive_pmu'] === 0, 'missing pmu defaults to 0');
assertTest($byFunc['foo()']['exclusive_cpu'] === 0, 'missing cpu exclusive is 0');

// --- Test 6: Empty profile ---
echo "\nTest: Empty profile\n";
assertTest(computeExclusiveTimes([]) === [], 'empty profile returns empty array');

// --- Summary ---
printTestSummary();
