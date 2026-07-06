<?php
// #ddev-generated
// Remove the line above if you want to keep local modifications to this file
// when the add-on is removed or upgraded.
/**
 * XHGui CLI Query Tool
 *
 * Queries XHGui profiling data from the CLI. Designed for use as a DDEV web command.
 */

ini_set('memory_limit', '256M');

// --- Constants ---

// Timezone convention: JSON output uses gmdate() (UTC) for machine consumption.
// Table output uses date() (local timezone) for human readability.

// Keep in sync with the git release tag (see docs/plans/2026-07-06-1.0-release-plan.md)
const XHGUI_CLI_VERSION = '0.1.1';

const EXIT_SUCCESS = 0;
const EXIT_USAGE = 1;
const EXIT_INFRA = 2;
const EXIT_DATA = 3;

const SORT_MAP_RUNS = [
    'time' => 'request_ts DESC',
    'wt'   => 'main_wt DESC',
    'cpu'  => 'main_cpu DESC',
    'pmu'  => 'main_pmu DESC',
];

const SORT_MAP_FUNCTIONS = [
    'wt'  => 'exclusive_wt',
    'cpu' => 'exclusive_cpu',
    'pmu' => 'exclusive_pmu',
];

// callers sorts on the raw per-edge metric, so keys map to themselves
const SORT_KEYS_CALLERS = ['wt', 'cpu', 'pmu'];

const VALID_FORMATS = ['table', 'json'];

const FLAGS_RUNS = ['limit', 'url', 'sort', 'format'];
const FLAGS_TOP_FUNCTIONS = ['run-id', 'limit', 'sort', 'format'];
const FLAGS_CALLERS = ['function', 'run-id', 'limit', 'sort', 'format'];

const SUBCOMMAND_FLAGS = [
    'runs'          => FLAGS_RUNS,
    'top-functions' => FLAGS_TOP_FUNCTIONS,
    'callers'       => FLAGS_CALLERS,
];

const LIMIT_MIN = 1;
const LIMIT_MAX = 1000;

const RUN_ID_PATTERN = '/^[0-9a-f]{24}$/i';

const LARGE_PROFILE_THRESHOLD = 20 * 1024 * 1024; // 20MB

// When included from tests, define XHGUI_CLI_TESTING to skip main execution
if (defined('XHGUI_CLI_TESTING')) {
    return;
}

// --- TTY / Format Detection ---

$isTty = getenv('XHGUI_IS_TTY') === '1';

// --- Argument Parsing ---

$subcommand = $argv[1] ?? null;

if ($subcommand === '--version') {
    echo 'xhgui-cli ' . XHGUI_CLI_VERSION . "\n";
    exit(EXIT_SUCCESS);
}

if ($subcommand === null || $subcommand === '--help' || $subcommand === '-h') {
    printUsage();
    exit(EXIT_SUCCESS);
}

if (!in_array($subcommand, ['runs', 'top-functions', 'callers'], true)) {
    fwrite(STDERR, "Error: Unknown subcommand '$subcommand'.\n");
    fwrite(STDERR, "Available subcommands: runs, top-functions, callers\n");
    fwrite(STDERR, "Run 'ddev xhgui-query --help' for usage.\n");
    exit(EXIT_USAGE);
}

// Parse flags from argv (manual parsing -- PHP's getopt() reads from process argv
// and BSD getopt stops at the first non-option argument like the subcommand name)
$parsed = parseArgs(array_slice($argv, 2), SUBCOMMAND_FLAGS[$subcommand]);
$opts = $parsed['opts'];

if (isset($opts['help'])) {
    printUsage($subcommand);
    exit(EXIT_SUCCESS);
}

// --- Validate Common Options ---

// Resolve format before reporting parse errors so the JSON error envelope applies
$format = $opts['format'] ?? ($isTty ? 'table' : 'json');
if (!in_array($format, VALID_FORMATS, true)) {
    exitError("Invalid --format '$format'. Valid values: " . implode(', ', VALID_FORMATS), EXIT_USAGE, $format);
}

if ($parsed['errors'] !== []) {
    exitError(
        implode("\n", $parsed['errors']) . "\nRun 'ddev xhgui-query $subcommand --help' for usage.",
        EXIT_USAGE,
        $format
    );
}

$limit = isset($opts['limit']) ? (int)$opts['limit'] : ($subcommand === 'runs' ? 20 : 10);
if ($limit < LIMIT_MIN || $limit > LIMIT_MAX) {
    exitError("--limit must be between " . LIMIT_MIN . " and " . LIMIT_MAX . ".", EXIT_USAGE, $format);
}

if ($subcommand === 'callers' && (!isset($opts['function']) || $opts['function'] === '')) {
    exitError(
        "--function is required. Use the exact name shown by 'ddev xhgui-query top-functions'.\nRun 'ddev xhgui-query callers --help' for usage.",
        EXIT_USAGE,
        $format
    );
}

// --- Database Connection ---

$dbFamily = getenv('DDEV_DATABASE_FAMILY') ?: '';
if ($dbFamily !== '' && $dbFamily !== 'mysql') {
    exitError("Unsupported database: $dbFamily. Only MySQL/MariaDB is supported.", EXIT_INFRA, $format);
}

try {
    $pdo = new PDO(
        'mysql:host=db;port=3306;dbname=xhgui;charset=utf8mb4',
        'db',
        'db',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    $code = (int)$e->getCode();
    if ($code === 1049) {
        exitError("XHGui database not found. Run 'ddev xhgui on' to enable profiling.", EXIT_INFRA, $format);
    }
    exitError("Could not connect to the XHGui database.", EXIT_INFRA, $format);
}

// Check results table exists
try {
    $pdo->query("SELECT 1 FROM results LIMIT 1");
} catch (PDOException $e) {
    exitError("XHGui 'results' table not found. Run 'ddev xhgui on' and visit pages to generate data.", EXIT_INFRA, $format);
}

// --- Execute Subcommand ---

if ($subcommand === 'runs') {
    executeRuns($pdo, $opts, $format, $limit);
} elseif ($subcommand === 'top-functions') {
    executeTopFunctions($pdo, $opts, $format, $limit);
} else {
    executeCallers($pdo, $opts, $format, $limit);
}

// === Subcommand Implementations ===

function executeRuns(PDO $pdo, array $opts, string $format, int $limit): void
{
    $sortKey = $opts['sort'] ?? 'time';
    if (!isset(SORT_MAP_RUNS[$sortKey])) {
        exitError("Invalid --sort '$sortKey'. Valid values: " . implode(', ', array_keys(SORT_MAP_RUNS)), EXIT_USAGE, $format);
    }
    $orderBy = SORT_MAP_RUNS[$sortKey];

    $sql = "SELECT id, url, main_wt, main_cpu, main_pmu, request_ts FROM results";
    $params = [];

    if (isset($opts['url'])) {
        $urlPattern = $opts['url'];
        // Escape LIKE wildcards in user input
        $urlPattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $urlPattern);
        $sql .= " WHERE url LIKE :url ESCAPE '\\\\'";
        $params[':url'] = '%' . $urlPattern . '%';
    }

    $sql .= " ORDER BY $orderBy LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        if ($format === 'json') {
            echo json_encode([]);
            fwrite(STDERR, "No profiling runs found. Run 'ddev xhgui on' and visit pages to generate data.\n");
        } else {
            echo "No profiling runs found. Run 'ddev xhgui on' and visit pages to generate data.\n";
        }
        exit(EXIT_SUCCESS);
    }

    if ($format === 'json') {
        $output = array_map(function ($row) {
            return [
                'id'               => $row['id'],
                'url'              => $row['url'],
                'wall_time_us'     => (int)$row['main_wt'],
                'cpu_time_us'      => (int)$row['main_cpu'],
                'peak_memory_bytes' => (int)$row['main_pmu'],
                'timestamp'        => gmdate('Y-m-d\TH:i:s\Z', (int)$row['request_ts']),
            ];
        }, $rows);
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        printRunsTable($rows);
    }

    exit(EXIT_SUCCESS);
}

function executeTopFunctions(PDO $pdo, array $opts, string $format, int $limit): void
{
    $sortKey = $opts['sort'] ?? 'wt';
    if (!isset(SORT_MAP_FUNCTIONS[$sortKey])) {
        exitError("Invalid --sort '$sortKey'. Valid values: " . implode(', ', array_keys(SORT_MAP_FUNCTIONS)), EXIT_USAGE, $format);
    }

    $loaded = loadRunProfile($pdo, $opts['run-id'] ?? null, $format);

    if ($loaded === null) {
        if ($format === 'json') {
            echo json_encode([
                'run_id'    => null,
                'url'       => null,
                'timestamp' => null,
                'functions' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            fwrite(STDERR, "No profiling runs found. Run 'ddev xhgui on' and visit pages to generate data.\n");
        } else {
            echo "No profiling runs found. Run 'ddev xhgui on' and visit pages to generate data.\n";
        }
        exit(EXIT_SUCCESS);
    }

    $run = $loaded['run'];

    // Compute exclusive times
    $functions = computeExclusiveTimes($loaded['profile']);

    // Sort
    $sortField = SORT_MAP_FUNCTIONS[$sortKey];
    usort($functions, function ($a, $b) use ($sortField) {
        return $b[$sortField] <=> $a[$sortField];
    });

    // Limit
    $functions = array_slice($functions, 0, $limit);

    if ($format === 'json') {
        $output = [
            'run_id'    => $run['id'],
            'url'       => $run['url'],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', (int)$run['request_ts']),
            'functions' => array_map(function ($f) {
                return [
                    'function'                  => $f['function'],
                    'call_count'                => $f['call_count'],
                    'inclusive_wall_time_us'     => $f['inclusive_wt'],
                    'exclusive_wall_time_us'     => $f['exclusive_wt'],
                    'inclusive_cpu_time_us'      => $f['inclusive_cpu'],
                    'exclusive_cpu_time_us'      => $f['exclusive_cpu'],
                    'inclusive_peak_memory_bytes' => $f['inclusive_pmu'],
                    'exclusive_peak_memory_bytes' => $f['exclusive_pmu'],
                ];
            }, $functions),
        ];
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        printFunctionsTable($functions);
    }

    exit(EXIT_SUCCESS);
}

function executeCallers(PDO $pdo, array $opts, string $format, int $limit): void
{
    $sortKey = $opts['sort'] ?? 'wt';
    if (!in_array($sortKey, SORT_KEYS_CALLERS, true)) {
        exitError("Invalid --sort '$sortKey'. Valid values: " . implode(', ', SORT_KEYS_CALLERS), EXIT_USAGE, $format);
    }

    $function = $opts['function']; // presence validated before dispatch

    $loaded = loadRunProfile($pdo, $opts['run-id'] ?? null, $format);

    if ($loaded === null) {
        if ($format === 'json') {
            echo json_encode([
                'run_id'    => null,
                'url'       => null,
                'timestamp' => null,
                'function'  => $function,
                'total'     => null,
                'callers'   => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            fwrite(STDERR, "No profiling runs found. Run 'ddev xhgui on' and visit pages to generate data.\n");
        } else {
            echo "No profiling runs found. Run 'ddev xhgui on' and visit pages to generate data.\n";
        }
        exit(EXIT_SUCCESS);
    }

    $run = $loaded['run'];

    $result = computeCallers($loaded['profile'], $function);
    if ($result === null) {
        exitError(
            "Function '$function' not found in run '{$run['id']}'.\nRun 'ddev xhgui-query top-functions --run-id {$run['id']}' for exact function names.",
            EXIT_DATA,
            $format
        );
    }

    $callers = $result['callers'];
    usort($callers, function ($a, $b) use ($sortKey) {
        return $b[$sortKey] <=> $a[$sortKey];
    });
    $callers = array_slice($callers, 0, $limit);

    if ($format === 'json') {
        $total = $result['total'];
        $output = [
            'run_id'    => $run['id'],
            'url'       => $run['url'],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', (int)$run['request_ts']),
            'function'  => $function,
            'total'     => [
                'call_count'        => $total['ct'],
                'wall_time_us'      => $total['wt'],
                'cpu_time_us'       => $total['cpu'],
                'peak_memory_bytes' => $total['pmu'],
            ],
            'callers'   => array_map(function ($c) {
                return [
                    'caller'            => $c['caller'],
                    'call_count'        => $c['ct'],
                    'wall_time_us'      => $c['wt'],
                    'cpu_time_us'       => $c['cpu'],
                    'peak_memory_bytes' => $c['pmu'],
                    'wall_time_pct'     => $c['wt_pct'],
                    'cpu_time_pct'      => $c['cpu_pct'],
                    'peak_memory_pct'   => $c['pmu_pct'],
                ];
            }, $callers),
        ];
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($callers === []) {
            fwrite(STDERR, "Function '$function' has no callers in this run.\n");
        }
    } else {
        if ($callers === []) {
            echo "Function '" . sanitizeTableValue($function) . "' has no callers in this run.\n";
        } else {
            printCallersTable($callers);
        }
    }

    exit(EXIT_SUCCESS);
}

/**
 * Resolve a run (explicit --run-id or most recent) and decode its profile blob.
 * Shared by top-functions and callers so both fetch and decode the edge map
 * exactly once, with identical validation and error classes.
 *
 * Returns ['run' => row, 'profile' => array], or null when the results table
 * holds no runs at all (the caller prints its own empty-result shape).
 */
function loadRunProfile(PDO $pdo, ?string $runId, string $format): ?array
{
    if ($runId !== null && !preg_match(RUN_ID_PATTERN, $runId)) {
        exitError("Invalid --run-id format. Expected 24 hex characters (e.g., abc123def456789012345678).\nRun 'ddev xhgui-query runs' to see available run IDs.", EXIT_USAGE, $format);
    }

    // Fetch the run
    if ($runId !== null) {
        $stmt = $pdo->prepare("SELECT id, url, request_ts, LENGTH(profile) as profile_length FROM results WHERE id = :id");
        $stmt->bindValue(':id', $runId);
        $stmt->execute();
        $run = $stmt->fetch();

        if (!$run) {
            exitError("Run ID '$runId' not found.\nRun 'ddev xhgui-query runs' to see available run IDs.", EXIT_DATA, $format);
        }
    } else {
        $stmt = $pdo->query("SELECT id, url, request_ts, LENGTH(profile) as profile_length FROM results ORDER BY request_ts DESC LIMIT 1");
        $run = $stmt->fetch();

        if (!$run) {
            return null;
        }

        fwrite(STDERR, sprintf(
            "Using most recent run: %s (%s, %s)\n",
            $run['id'],
            $run['url'] ?? '(unknown)',
            date('Y-m-d H:i', (int)$run['request_ts'])
        ));
    }

    // Profile size warning
    $profileLength = (int)$run['profile_length'];
    if ($profileLength > LARGE_PROFILE_THRESHOLD) {
        fwrite(STDERR, sprintf("Warning: Large profile blob (%.1f MB). This may take a moment.\n", $profileLength / 1048576));
    }

    // Fetch profile
    $stmt = $pdo->prepare("SELECT profile FROM results WHERE id = :id");
    $stmt->bindValue(':id', $run['id']);
    $stmt->execute();
    $profileData = $stmt->fetchColumn();

    if ($profileData === false || $profileData === null || $profileData === '') {
        exitError("Profile data is empty for run '{$run['id']}'.", EXIT_DATA, $format);
    }

    // Detect gzip (migration edge case)
    $profile = decodeProfile($profileData);
    unset($profileData);
    if ($profile === null) {
        exitError("Failed to decode profile data for run '{$run['id']}'. The profile blob may be corrupt.", EXIT_DATA, $format);
    }

    return ['run' => $run, 'profile' => $profile];
}

// === Profile Processing ===

function decodeProfile(string $data): ?array
{
    // Detect gzip by magic bytes
    if (strlen($data) >= 2 && $data[0] === "\x1f" && $data[1] === "\x8b") {
        $data = @gzdecode($data);
        if ($data === false) {
            return null;
        }
    }

    $profile = @json_decode($data, true);
    if (!is_array($profile)) {
        return null;
    }
    return $profile;
}

/**
 * Compute exclusive times using O(F) hash map algorithm.
 *
 * 1. Single-pass: for each caller==>callee key, build inclusive totals per function
 *    and aggregate child sums per parent inline (O(F) memory, not O(E))
 * 2. Exclusive = inclusive - aggregated children's inclusive
 * 3. Floor negative values at 0 (XHProf shared-callee limitation)
 */
function computeExclusiveTimes(array $profile): array
{
    $inclusive  = [];  // function_name => [wt, cpu, pmu, ct]
    $childSums = [];   // parent_name => [wt, cpu, pmu] (aggregated inline)

    foreach ($profile as $key => $metrics) {
        $wt  = $metrics['wt']  ?? 0;
        $cpu = $metrics['cpu'] ?? 0;
        $pmu = $metrics['pmu'] ?? 0;
        $ct  = $metrics['ct']  ?? 0;

        $parts = explode('==>', $key);
        if (isset($parts[1])) {
            $callee = $parts[1];
            $parent = $parts[0];
            // Accumulate inclusive for callee
            if (!isset($inclusive[$callee])) {
                $inclusive[$callee] = ['wt' => 0, 'cpu' => 0, 'pmu' => 0, 'ct' => 0];
            }
            $inclusive[$callee]['wt']  += $wt;
            $inclusive[$callee]['cpu'] += $cpu;
            $inclusive[$callee]['pmu'] += $pmu;
            $inclusive[$callee]['ct']  += $ct;

            // Aggregate child sums for parent inline
            if (!isset($childSums[$parent])) {
                $childSums[$parent] = ['wt' => 0, 'cpu' => 0, 'pmu' => 0];
            }
            $childSums[$parent]['wt']  += $wt;
            $childSums[$parent]['cpu'] += $cpu;
            $childSums[$parent]['pmu'] += $pmu;
        } else {
            // Root entry (e.g., main())
            $func = $parts[0];
            if (!isset($inclusive[$func])) {
                $inclusive[$func] = ['wt' => 0, 'cpu' => 0, 'pmu' => 0, 'ct' => 0];
            }
            $inclusive[$func]['wt']  += $wt;
            $inclusive[$func]['cpu'] += $cpu;
            $inclusive[$func]['pmu'] += $pmu;
            $inclusive[$func]['ct']  += $ct;
        }
    }

    // Compute exclusive = inclusive - aggregated children's inclusive
    $result = [];
    foreach ($inclusive as $func => $metrics) {
        $cs = $childSums[$func] ?? ['wt' => 0, 'cpu' => 0, 'pmu' => 0];

        $result[] = [
            'function'      => $func,
            'call_count'    => $metrics['ct'],
            'inclusive_wt'  => $metrics['wt'],
            'exclusive_wt'  => max(0, $metrics['wt'] - $cs['wt']),
            'inclusive_cpu' => $metrics['cpu'],
            'exclusive_cpu' => max(0, $metrics['cpu'] - $cs['cpu']),
            'inclusive_pmu' => $metrics['pmu'],
            'exclusive_pmu' => max(0, $metrics['pmu'] - $cs['pmu']),
        ];
    }

    return $result;
}

/**
 * Aggregate the caller edges for one function from XHProf's caller==>callee map.
 *
 * Single pass, O(callers-of-function) memory. The totals sum every edge into
 * the function plus any root entry, matching the inclusive totals reported by
 * computeExclusiveTimes(), so each edge's share is relative to the same number
 * top-functions prints.
 *
 * Returns null when the function appears nowhere in the profile. A function
 * with no incoming edges (e.g. main()) returns 'callers' => [].
 */
function computeCallers(array $profile, string $function): ?array
{
    $edges = [];  // caller name => [ct, wt, cpu, pmu]
    $total = ['ct' => 0, 'wt' => 0, 'cpu' => 0, 'pmu' => 0];
    $seen  = false;

    foreach ($profile as $key => $metrics) {
        $parts  = explode('==>', $key);
        $caller = isset($parts[1]) ? $parts[0] : null;
        $callee = $parts[1] ?? $parts[0];

        if ($callee !== $function) {
            if ($caller === $function) {
                $seen = true; // appears in the profile, but only as a caller
            }
            continue;
        }
        $seen = true;

        $ct  = $metrics['ct']  ?? 0;
        $wt  = $metrics['wt']  ?? 0;
        $cpu = $metrics['cpu'] ?? 0;
        $pmu = $metrics['pmu'] ?? 0;

        $total['ct']  += $ct;
        $total['wt']  += $wt;
        $total['cpu'] += $cpu;
        $total['pmu'] += $pmu;

        if ($caller === null) {
            continue; // root entry: counts toward totals but has no caller row
        }

        if (!isset($edges[$caller])) {
            $edges[$caller] = ['ct' => 0, 'wt' => 0, 'cpu' => 0, 'pmu' => 0];
        }
        $edges[$caller]['ct']  += $ct;
        $edges[$caller]['wt']  += $wt;
        $edges[$caller]['cpu'] += $cpu;
        $edges[$caller]['pmu'] += $pmu;
    }

    if (!$seen) {
        return null;
    }

    $callers = [];
    foreach ($edges as $caller => $m) {
        $callers[] = [
            'caller'  => $caller,
            'ct'      => $m['ct'],
            'wt'      => $m['wt'],
            'cpu'     => $m['cpu'],
            'pmu'     => $m['pmu'],
            'wt_pct'  => $total['wt']  > 0 ? round($m['wt']  / $total['wt']  * 100, 1) : 0.0,
            'cpu_pct' => $total['cpu'] > 0 ? round($m['cpu'] / $total['cpu'] * 100, 1) : 0.0,
            'pmu_pct' => $total['pmu'] > 0 ? round($m['pmu'] / $total['pmu'] * 100, 1) : 0.0,
        ];
    }

    return ['total' => $total, 'callers' => $callers];
}

// === Output Formatting ===

function printRunsTable(array $rows): void
{
    $headers = ['RUN ID', 'URL', 'WALL (ms)', 'CPU (ms)', 'PEAK MEM (MB)', 'DATE'];
    $data = [];

    foreach ($rows as $row) {
        $data[] = [
            sanitizeTableValue(substr($row['id'], 0, 11)),
            sanitizeTableValue(truncate($row['url'] ?? '', 40)),
            number_format((int)$row['main_wt'] / 1000, 1),
            number_format((int)$row['main_cpu'] / 1000, 1),
            number_format((int)$row['main_pmu'] / 1048576, 1),
            date('Y-m-d H:i', (int)$row['request_ts']),
        ];
    }

    printTable($headers, $data);
}

function printFunctionsTable(array $functions): void
{
    $headers = ['FUNCTION', 'CALLS', 'EXCL WALL (ms)', 'INCL WALL (ms)', 'EXCL CPU (ms)', 'EXCL MEM (MB)'];
    $data = [];

    foreach ($functions as $f) {
        $data[] = [
            sanitizeTableValue(truncate($f['function'], 40)),
            (string)$f['call_count'],
            number_format($f['exclusive_wt'] / 1000, 1),
            number_format($f['inclusive_wt'] / 1000, 1),
            number_format($f['exclusive_cpu'] / 1000, 1),
            number_format($f['exclusive_pmu'] / 1048576, 1),
        ];
    }

    printTable($headers, $data);
}

function printCallersTable(array $callers): void
{
    $headers = ['CALLER', 'CALLS', 'WALL (ms)', 'WALL %', 'CPU (ms)', 'MEM (MB)'];
    $data = [];

    foreach ($callers as $c) {
        $data[] = [
            sanitizeTableValue(truncate($c['caller'], 40)),
            (string)$c['ct'],
            number_format($c['wt'] / 1000, 1),
            number_format($c['wt_pct'], 1),
            number_format($c['cpu'] / 1000, 1),
            number_format($c['pmu'] / 1048576, 1),
        ];
    }

    printTable($headers, $data);
}

function printTable(array $headers, array $data): void
{
    // Calculate column widths
    $widths = array_map('strlen', $headers);
    foreach ($data as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i], strlen($cell));
        }
    }

    // Print header
    $line = '';
    foreach ($headers as $i => $header) {
        $line .= str_pad($header, $widths[$i] + 2);
    }
    echo rtrim($line) . "\n";

    // Print rows
    foreach ($data as $row) {
        $line = '';
        foreach ($row as $i => $cell) {
            $line .= str_pad($cell, $widths[$i] + 2);
        }
        echo rtrim($line) . "\n";
    }
}

function truncate(string $str, int $maxLen): string
{
    if (strlen($str) <= $maxLen) {
        return $str;
    }
    return substr($str, 0, $maxLen - 3) . '...';
}

function sanitizeTableValue(string $value): string
{
    // Strip non-printable and control characters (prevents terminal escape injection)
    return preg_replace('/[\x00-\x1f\x7f]/', '', $value);
}

// === Argument Parsing ===

/**
 * Parse --flag value and --flag=value pairs from argv array.
 * Manual implementation because PHP's getopt() reads from process argv
 * and BSD getopt stops at the first non-option argument (the subcommand).
 *
 * Unknown flags, bare arguments, and flags missing a value are collected
 * as errors rather than reported here, so the caller can resolve --format
 * first and honor the JSON error envelope contract.
 *
 * Returns ['opts' => [...], 'errors' => [...]].
 */
function parseArgs(array $args, array $allowedFlags): array
{
    $opts = [];
    $errors = [];
    $count = count($args);

    for ($i = 0; $i < $count; $i++) {
        $arg = $args[$i];

        if (strpos($arg, '--') !== 0) {
            $errors[] = "Unexpected argument '$arg'.";
            continue;
        }

        $key = substr($arg, 2);
        if ($key === '' || $key === false) {
            $errors[] = "Unexpected argument '--'.";
            continue;
        }

        $value = null;
        $eq = strpos($key, '=');
        if ($eq !== false) {
            $value = substr($key, $eq + 1);
            $value = ($value === false) ? '' : $value;
            $key = substr($key, 0, $eq);
        }

        if ($key === 'help') {
            $opts['help'] = true;
            continue;
        }

        if (!in_array($key, $allowedFlags, true)) {
            $errors[] = "Unknown flag '--$key'.";
            // Consume a space-separated value so it doesn't also error as a bare argument
            if ($value === null && $i + 1 < $count && strpos($args[$i + 1], '--') !== 0) {
                $i++;
            }
            continue;
        }

        if ($value === null) {
            if ($i + 1 < $count && strpos($args[$i + 1], '--') !== 0) {
                $i++;
                $value = $args[$i];
            } else {
                $errors[] = "Flag '--$key' requires a value.";
                continue;
            }
        }

        $opts[$key] = $value;
    }

    return ['opts' => $opts, 'errors' => $errors];
}

// === Error Handling ===

function exitError(string $message, int $exitCode, string $format): void
{
    fwrite(STDERR, "Error: $message\n");

    if ($format === 'json') {
        echo json_encode([
            'error' => [
                'code'    => $exitCode,
                'message' => $message,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    exit($exitCode);
}

// === Usage ===

function printUsage(?string $subcommand = null): void
{
    if ($subcommand === 'runs') {
        echo <<<'USAGE'
Usage: ddev xhgui-query runs [flags]

List recent profiling runs.

Flags:
  --limit <n>     Number of runs (default: 20, max: 1000)
  --url <pattern> Filter by URL substring
  --sort <field>  Sort by: time (default), wt, cpu, pmu
  --format <fmt>  Output format: table, json (auto-detected)
  --help          Show this help

USAGE;
    } elseif ($subcommand === 'top-functions') {
        echo <<<'USAGE'
Usage: ddev xhgui-query top-functions [flags]

Show function-level exclusive time breakdown for a profiling run.

Flags:
  --run-id <id>   Target a specific run (default: most recent)
  --limit <n>     Number of functions (default: 10, max: 1000)
  --sort <field>  Sort by exclusive: wt (default), cpu, pmu
  --format <fmt>  Output format: table, json (auto-detected)
  --help          Show this help

USAGE;
    } elseif ($subcommand === 'callers') {
        echo <<<'USAGE'
Usage: ddev xhgui-query callers --function <name> [flags]

Show which functions call a given function, with each caller's share of its cost.

Flags:
  --function <name>  Required. Exact name as shown by top-functions
  --run-id <id>      Target a specific run (default: most recent)
  --limit <n>        Number of callers (default: 10, max: 1000)
  --sort <field>     Sort by per-caller: wt (default), cpu, pmu
  --format <fmt>     Output format: table, json (auto-detected)
  --help             Show this help

USAGE;
    } else {
        echo <<<'USAGE'
Usage: ddev xhgui-query <subcommand> [flags]

Query XHGui profiling data from the CLI.

Subcommands:
  runs            List recent profiling runs
  top-functions   Show function-level exclusive time breakdown
  callers         Show who calls a function and each caller's share

Flags:
  --version       Print the tool version
  --help          Show this help

Run 'ddev xhgui-query <subcommand> --help' for details.

USAGE;
    }
}
