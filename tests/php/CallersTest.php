<?php
/**
 * Unit tests for the callers aggregation (computeCallers).
 * Self-contained — runs with: php tests/php/CallersTest.php
 */

require __DIR__ . '/bootstrap.php';

// --- Test 1: Multi-caller aggregation with shares ---
echo "Test: Multiple callers with shares\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 100000, 'cpu' => 80000, 'pmu' => 5000000],
    'main()==>repo()' => ['ct' => 1, 'wt' => 60000, 'cpu' => 50000, 'pmu' => 3000000],
    'main()==>render()' => ['ct' => 1, 'wt' => 20000, 'cpu' => 15000, 'pmu' => 1000000],
    'repo()==>query()' => ['ct' => 40, 'wt' => 24000, 'cpu' => 6000, 'pmu' => 400000],
    'render()==>query()' => ['ct' => 8, 'wt' => 6000, 'cpu' => 2000, 'pmu' => 100000],
];

$result = computeCallers($profile, 'query()');
assertTest($result !== null, 'query() found in profile');
assertTest($result['total']['ct'] === 48, 'total call count sums edges');
assertTest($result['total']['wt'] === 30000, 'total wt sums edges');
assertTest(count($result['callers']) === 2, 'two caller rows');

$byCaller = [];
foreach ($result['callers'] as $c) {
    $byCaller[$c['caller']] = $c;
}
assertTest($byCaller['repo()']['ct'] === 40, 'repo() edge call count');
assertTest($byCaller['repo()']['wt'] === 24000, 'repo() edge wt');
assertTest($byCaller['repo()']['wt_pct'] === 80.0, 'repo() share = 24000/30000 = 80%');
assertTest($byCaller['render()']['wt_pct'] === 20.0, 'render() share = 6000/30000 = 20%');
assertTest($byCaller['repo()']['cpu_pct'] === 75.0, 'repo() cpu share = 6000/8000 = 75%');
assertTest($byCaller['repo()']['pmu_pct'] === 80.0, 'repo() pmu share = 400000/500000 = 80%');

// --- Test 2: Single caller gets 100% ---
echo "\nTest: Single caller\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 50000, 'cpu' => 40000, 'pmu' => 2000000],
    'main()==>foo()' => ['ct' => 3, 'wt' => 30000, 'cpu' => 25000, 'pmu' => 1500000],
];

$result = computeCallers($profile, 'foo()');
assertTest($result !== null, 'foo() found');
assertTest(count($result['callers']) === 1, 'one caller row');
assertTest($result['callers'][0]['caller'] === 'main()', 'caller is main()');
assertTest($result['callers'][0]['wt_pct'] === 100.0, 'single caller share is 100%');
assertTest($result['total']['ct'] === 3, 'total ct matches the single edge');

// --- Test 3: Recursive edge (function calls itself) ---
echo "\nTest: Recursive edge\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 100000, 'cpu' => 80000, 'pmu' => 4000000],
    'main()==>fib()' => ['ct' => 1, 'wt' => 60000, 'cpu' => 50000, 'pmu' => 2000000],
    'fib()==>fib()' => ['ct' => 20, 'wt' => 40000, 'cpu' => 30000, 'pmu' => 1000000],
];

$result = computeCallers($profile, 'fib()');
assertTest($result !== null, 'fib() found');
assertTest(count($result['callers']) === 2, 'recursive edge yields a self-caller row');
$byCaller = [];
foreach ($result['callers'] as $c) {
    $byCaller[$c['caller']] = $c;
}
assertTest(isset($byCaller['fib()']), 'fib() appears as its own caller');
assertTest($byCaller['fib()']['ct'] === 20, 'recursive edge call count');
assertTest($result['total']['wt'] === 100000, 'total includes the recursive edge');
assertTest($byCaller['fib()']['wt_pct'] === 40.0, 'recursive edge share of total');

// --- Test 4: Function absent from profile returns null ---
echo "\nTest: Function absent\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 50000, 'cpu' => 40000, 'pmu' => 2000000],
    'main()==>foo()' => ['ct' => 1, 'wt' => 30000, 'cpu' => 25000, 'pmu' => 1500000],
];

assertTest(computeCallers($profile, 'nope()') === null, 'unknown function returns null');
assertTest(computeCallers([], 'main()') === null, 'empty profile returns null');

// --- Test 5: Root entry (main()) has no callers but is present ---
echo "\nTest: Root entry has no callers\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 50000, 'cpu' => 40000, 'pmu' => 2000000],
    'main()==>foo()' => ['ct' => 1, 'wt' => 30000, 'cpu' => 25000, 'pmu' => 1500000],
];

$result = computeCallers($profile, 'main()');
assertTest($result !== null, 'main() is present in the profile');
assertTest($result['callers'] === [], 'main() has no caller rows');
assertTest($result['total']['wt'] === 50000, 'root entry counts toward totals');

// --- Test 6: Totals match computeExclusiveTimes inclusive numbers ---
echo "\nTest: Totals agree with top-functions inclusive\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 100000, 'cpu' => 80000, 'pmu' => 5000000],
    'main()==>foo()' => ['ct' => 2, 'wt' => 80000, 'cpu' => 70000, 'pmu' => 4000000],
    'foo()==>bar()' => ['ct' => 3, 'wt' => 30000, 'cpu' => 25000, 'pmu' => 1000000],
    'bar()==>foo()' => ['ct' => 1, 'wt' => 10000, 'cpu' => 8000, 'pmu' => 500000],
];

$callersResult = computeCallers($profile, 'foo()');
$byFunc = [];
foreach (computeExclusiveTimes($profile) as $f) {
    $byFunc[$f['function']] = $f;
}
assertTest(
    $callersResult['total']['wt'] === $byFunc['foo()']['inclusive_wt'],
    'callers total wt equals top-functions inclusive wt'
);
assertTest(
    $callersResult['total']['cpu'] === $byFunc['foo()']['inclusive_cpu'],
    'callers total cpu equals top-functions inclusive cpu'
);

// --- Test 7: Missing metric keys default to 0, zero totals guard shares ---
echo "\nTest: Missing metrics and zero-total shares\n";
$profile = [
    'main()' => ['ct' => 1, 'wt' => 50000],
    'main()==>foo()' => ['ct' => 2, 'wt' => 20000],
];

$result = computeCallers($profile, 'foo()');
assertTest($result['total']['cpu'] === 0, 'missing cpu defaults to 0');
assertTest($result['callers'][0]['cpu_pct'] === 0.0, 'zero cpu total yields 0.0 share, no division by zero');
assertTest($result['callers'][0]['pmu_pct'] === 0.0, 'zero pmu total yields 0.0 share');
assertTest($result['callers'][0]['wt_pct'] === 100.0, 'wt share still computed');

// --- Summary ---
printTestSummary();
