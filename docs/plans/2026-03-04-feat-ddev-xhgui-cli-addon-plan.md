---
title: "feat: DDEV XHGui CLI Addon"
type: feat
status: active
date: 2026-03-04
deepened: 2026-03-04
---

# DDEV XHGui CLI Addon

## Enhancement Summary

**Deepened on:** 2026-03-04
**Research agents used:** performance-oracle, security-sentinel, code-simplicity-reviewer, architecture-strategist, agent-native-reviewer, pattern-recognition-specialist, best-practices-researcher, framework-docs-researcher

### Key Improvements

1. **Scope tightened to two commands** (`runs` + `top-functions`) for v0.1.0 -- `compare` and `export` deferred to v0.2.0 per simplicity review
2. **Security constraints section added** -- allowlist for `--sort`, parameterized queries, input validation rules
3. **Performance-optimized exclusive time algorithm** -- O(E) hash map instead of naive O(F^2) approach
4. **JSON error envelope** for agent consumers when errors occur in JSON mode
5. **Consistent JSON schema naming** -- fixed `cpu_us` vs `cpu_time_us` inconsistency across commands
6. **Bash wrapper hardened** -- env var for TTY instead of flag, `set -eu -o pipefail`, `#!/usr/bin/env bash`

### New Considerations Discovered

- DDEV v1.25.0 made XHGui the default profiler mode -- the archived `ddev/ddev-xhgui` addon confirms this tool fills a real gap
- PHP memory limit must be set explicitly (`256M`) for large profile blobs
- `--sort` maps to SQL `ORDER BY` column names which cannot be parameterized via PDO -- requires allowlist
- `PDO::ATTR_EMULATE_PREPARES => false` is required for correct `LIMIT` parameterization on MariaDB

## Overview

Build `ddev-xhgui-cli` -- a DDEV addon that exposes XHGui profiling data through CLI commands. Developers and AI agents can query wall time, CPU time, memory usage, and function-level metrics from the terminal without opening a browser.

DDEV's built-in XHGui integration (v1.24.4+, default since v1.25.0) captures profiling data in a MySQL/MariaDB database but only exposes it through a web UI at `https://project.ddev.site:8143`. The existing commands (`ddev xhgui on|off|status|launch`) manage the profiler but cannot retrieve results.

## Problem Statement

1. **No CLI access to profiling data** -- developers must open a browser to inspect results
2. **Agent workflows blocked** -- AI agents cannot programmatically analyze performance data
3. **No scriptable performance checks** -- CI/CD pipelines cannot query profiling results

Current workaround is limited to top-level metrics only:
```bash
ddev mysql -udb -pdb xhgui -e "SELECT url, main_wt, main_cpu, main_pmu FROM results ORDER BY main_wt DESC LIMIT 10;"
```

This misses function-level breakdowns, exclusive time calculations, and structured output.

## Proposed Solution

A DDEV addon with two components:

1. **Bash web command** (`commands/web/xhgui-query`) -- runs inside the web container with direct PHP/MySQL access. Handles TTY detection and delegates to PHP.
2. **PHP query script** (`xhgui-cli/query.php`) -- connects to the `xhgui` database, queries the `results` table, deserializes JSON profile blobs, computes exclusive metrics, and outputs formatted results.

### Why Web Command (Not Host Command)

- Direct access to PHP runtime and MySQL at `db:3306` -- no `ddev exec` overhead
- Profile blob parsing is trivial in PHP (~20 lines), awkward in Go
- Standard DDEV database credentials available inside the container
- `ExecRaw: true` ensures arguments pass through to PHP without DDEV's parser intercepting flags like `--help`

### Why Addon (Not DDEV Core PR)

- Ship in ~1 day vs weeks of PR review
- Easy to iterate on UX independently
- Installable via `ddev add-on get owner/ddev-xhgui-cli`
- Can upstream later if traction warrants it

## Commands (v0.1.0)

v0.1.0 ships two commands: `runs` and `top-functions`. These answer the two core questions: "which requests were slow?" and "what functions made them slow?" The `compare` and `export` commands are deferred to v0.2.0 -- they are convenience wrappers over these primitives that can be composed by agents and scripts using `jq`.

### `ddev xhgui-query runs`

List recent profiling runs from denormalized columns (no profile blob parsing needed).

```bash
ddev xhgui-query runs [--limit 20] [--url /path] [--sort time|wt|cpu|pmu] [--format table|json]
```

| Flag | Default | Description |
|------|---------|-------------|
| `--limit` | 20 | Number of runs to return (positive integer, max 1000) |
| `--url` | (none) | Substring filter on `url` column (`LIKE '%pattern%'`) |
| `--sort` | `time` | Sort by: `time` (request timestamp), `wt` (wall time), `cpu`, `pmu` (peak memory) |
| `--format` | auto | `table` when TTY, `json` when piped |

Note: `%` and `_` in `--url` are escaped before building the LIKE pattern. The LIKE clause uses an explicit `ESCAPE '\\'` for MariaDB/MySQL portability.

**Table output:**
```
RUN ID      URL                  WALL (ms)  CPU (ms)  PEAK MEM (MB)  DATE
abc123def   /shop/products       450.1      120.3     8.0            2026-03-04 14:23
def456ghi   /shop/cart           280.5       95.1     6.2            2026-03-04 14:22
```

**JSON output (schema contract for agents):**
```json
[
  {
    "id": "abc123def456789012345678",
    "url": "/shop/products",
    "wall_time_us": 450139,
    "cpu_time_us": 120300,
    "peak_memory_bytes": 8388608,
    "timestamp": "2026-03-04T14:23:00Z"
  }
]
```

**Empty results:** stdout outputs `[]`, stderr prints `No profiling runs found. Run "ddev xhgui on" and visit pages to generate data.`

**Error results (JSON mode):** stdout outputs `{"error": {"code": 2, "message": "XHGui database not found..."}}`, stderr prints the same message. Exit code matches the error code.

#### Research Insights: `runs`

- **SQL uses only denormalized columns** -- no `SELECT *`, explicitly list `id, url, main_wt, main_cpu, main_pmu, request_ts` to avoid fetching large `profile`, `SERVER`, `ENV`, `GET` columns. This is a significant performance win for tables with many rows.
- **`--sort` maps to hardcoded column names via allowlist** (security-critical: column names cannot be PDO-parameterized). Map: `time`->`request_ts DESC`, `wt`->`main_wt DESC`, `cpu`->`main_cpu DESC`, `pmu`->`main_pmu DESC`.
- **No indexes exist on `main_wt`/`main_cpu`/`main_pmu`** per XHGui's schema. Full table scan is acceptable for typical dev volumes (hundreds to low thousands of rows). The `LIMIT` clause allows MySQL to stop early for default `ORDER BY request_ts DESC`.
- **`--sort mu` was ambiguous** (could mean `main_mu` or `main_pmu`). Changed to `pmu` to explicitly mean peak memory, matching the table header "PEAK MEM."
- **`simple_url` removed from JSON schema** -- it is often identical to `url` in practice and adds noise without clear value for v0.1.0.

### `ddev xhgui-query top-functions`

Parse the profile blob and compute exclusive wall time for function-level breakdown.

```bash
ddev xhgui-query top-functions [--run-id ID] [--limit 10] [--sort wt|cpu|pmu] [--format table|json]
```

| Flag | Default | Description |
|------|---------|-------------|
| `--run-id` | (latest) | Specific run ID (24-char hex string); defaults to most recent run |
| `--limit` | 10 | Number of functions to return (positive integer, max 1000) |
| `--sort` | `wt` | Sort by exclusive: `wt` (wall time), `cpu`, `pmu` (peak memory) |
| `--format` | auto | `table` when TTY, `json` when piped |

When `--run-id` is omitted, a header line goes to stderr: `Using most recent run: <id> (<url>, <date>)`

**Table output:**
```
FUNCTION                     CALLS  EXCL WALL (ms)  INCL WALL (ms)  EXCL CPU (ms)  EXCL MEM (MB)
PDO::query                   47     82.0            85.0            1.8            0.4
Twig\Template::render        12     45.3            120.5           44.1           2.1
```

**Exclusive time calculation (O(E) algorithm):**
1. Single-pass the profile: for each `caller==>callee` key, build a hash map of `parent -> [child_metrics, ...]`
2. Collapse metrics per function (sum across all callers)
3. For each function, exclusive = inclusive - sum of direct children's inclusive (looked up from hash map)
4. Floor negative exclusive values at 0 (matches XHGui web UI -- happens due to XHProf's known shared-callee limitation)

```php
// Build children map in O(E) where E = number of caller-callee edges
$children = [];
foreach ($profile as $key => $metrics) {
    $parts = explode('==>', $key);
    if (isset($parts[1])) {
        $children[$parts[0]][] = $metrics;
    }
}
// Exclusive time per function is now O(F + E) total
```

**JSON output schema:**
```json
{
  "run_id": "abc123def456789012345678",
  "url": "/shop/products",
  "timestamp": "2026-03-04T14:23:00Z",
  "functions": [
    {
      "function": "PDO::query",
      "call_count": 47,
      "inclusive_wall_time_us": 85000,
      "exclusive_wall_time_us": 82000,
      "inclusive_cpu_time_us": 2000,
      "exclusive_cpu_time_us": 1800,
      "inclusive_peak_memory_bytes": 500000,
      "exclusive_peak_memory_bytes": 480000
    }
  ]
}
```

**Empty results (no runs in DB):** stdout outputs `{"run_id":null,"url":null,"timestamp":null,"functions":[]}`, stderr prints helpful message.

#### Research Insights: `top-functions`

- **JSON field naming made consistent with `runs`**: `inclusive_cpu_time_us` (not `inclusive_cpu_us`) to match `cpu_time_us` in `runs` schema. All time fields use `_time_us` suffix.
- **Memory fields simplified to peak memory only** (`inclusive_peak_memory_bytes`, `exclusive_peak_memory_bytes`). The `mu` (memory delta) metric from XHProf is less actionable than `pmu` (peak memory) and would double the field count. Can add in v0.2.0 if needed.
- **Profile blob pre-check**: Before fetching the full `profile` LONGTEXT, query `LENGTH(profile)` first. If > 20MB, emit a warning to stderr before proceeding. This prevents opaque OOM failures.
- **Missing metric keys**: Default `cpu`, `mu`, `pmu` to 0 if absent in profile entries (older collector versions may omit them).

## Commands (v0.2.0 -- Deferred)

### `ddev xhgui-query compare`

Side-by-side metrics for two URLs or two specific run IDs. Deferred because agents can compose this from `runs` + `jq`:
```bash
# Agent workflow: compare two URLs
LEFT=$(ddev xhgui-query runs --url /page-a --limit 1 | jq '.[0]')
RIGHT=$(ddev xhgui-query runs --url /page-b --limit 1 | jq '.[0]')
```

### `ddev xhgui-query export`

Full profile as structured JSON. Deferred because `top-functions --limit 500` covers most use cases, and raw profiles can be queried via `ddev mysql`.

## Technical Approach

### Database Schema (XHGui PDO)

```sql
-- xhgui.results table (denormalized for fast queries)
CREATE TABLE results (
  id               CHAR(24)       PRIMARY KEY,
  profile          LONGTEXT       NOT NULL,     -- JSON call graph
  url              TEXT           NULL,
  SERVER           TEXT           NULL,
  simple_url       TEXT           NULL,
  request_ts       INTEGER        NOT NULL,
  request_ts_micro NUMERIC(15,4)  NOT NULL,
  request_date     DATE           NOT NULL,
  main_wt          INTEGER        NOT NULL,     -- wall time (microseconds)
  main_ct          INTEGER        NOT NULL,     -- call count (always 1)
  main_cpu         INTEGER        NOT NULL,     -- CPU time (microseconds)
  main_mu          INTEGER        NOT NULL,     -- memory usage (bytes)
  main_pmu         INTEGER        NOT NULL      -- peak memory usage (bytes)
);
```

### Profile Blob Format

```json
{
  "main()": { "ct": 1, "wt": 50139, "cpu": 49513, "mu": 3449360, "pmu": 3535120 },
  "main()==>load_template()": { "ct": 1, "wt": 25000, "cpu": 20000, "mu": 200000, "pmu": 250000 }
}
```

Keys are `caller==>callee` pairs. `main()` is the root entry with no `==>`. Metrics are inclusive (include children). All times in microseconds, memory in bytes. The PDO backend stores this as plain `json_encode()` -- gzip compression only appears in databases migrated from the MongoDB backend (edge case, not the normal path).

### Output Conventions

**TTY detection via environment variable:**

The bash wrapper sets `XHGUI_IS_TTY=1|0` as an environment variable (not a CLI flag). This keeps `$argv` purely user-facing and avoids leaking an internal implementation detail into the PHP argument surface.

```bash
# In bash wrapper:
export XHGUI_IS_TTY=0
[ -t 1 ] && XHGUI_IS_TTY=1
exec php "$PHP_SCRIPT" "$@"
```

```php
// In PHP:
$isTty = getenv('XHGUI_IS_TTY') === '1';
$format = $opts['format'] ?? ($isTty ? 'table' : 'json');
```

Explicit `--format` flag overrides auto-detection.

**stdout vs stderr separation (critical for agent/CI consumers):**
- stdout: Only structured data (JSON or table). Must be parseable when piped.
- stderr: Human-readable messages (empty results guidance, "Using most recent run..." headers, warnings about skipped corrupt profiles).
- When JSON mode: empty results produce valid JSON on stdout (`[]`, `{"functions":[]}`, etc.), helpful message on stderr.
- When JSON mode + error: stdout outputs `{"error": {"code": N, "message": "..."}}` so agents can `json.loads(stdout)` unconditionally and check for an `error` key. Exit code still set for shell scripts.
- When table mode: empty results message goes to stdout (humans read it there).

**Timestamp format:** ISO 8601 (`2026-03-04T14:23:00Z`) in all JSON output. Human-readable (`2026-03-04 14:23`) in table output.

**Units in JSON:** Microseconds for time, bytes for memory. No conversion. Consumers handle formatting.

**Units in tables:** Milliseconds for time (1 decimal), MB for memory (1 decimal). Strip non-printable/control characters from string values (prevents terminal escape injection from URLs containing ANSI sequences).

### Security Constraints

1. **All SQL WHERE/HAVING values MUST use PDO prepared statement parameters. No exceptions.**
2. **`--sort` MUST map user input through a hardcoded allowlist to column names. Never interpolate user input into ORDER BY.** Column names cannot be parameterized via PDO -- this is the single most likely injection vector.
3. **`--limit` MUST be cast to `(int)` and clamped to range [1, 1000].** Use `PDO::PARAM_INT` for binding. Set `PDO::ATTR_EMULATE_PREPARES => false` for correct LIMIT parameterization on MariaDB.
4. **`--run-id` MUST be validated as `/^[0-9a-f]{24}$/i` before use.** Reject non-matching input with exit 1.
5. **Never use PHP `unserialize()` on profile data.** Only `json_decode()` after optional gzip decompression. `unserialize()` is an object injection vector.
6. **PDO exceptions MUST be caught and displayed as generic messages.** Full exception details only in stderr, never in JSON stdout output.
7. **`--format` and `--sort` values MUST be validated against allowlists.** Reject invalid values with exit 1.

### Error Handling

| Exit Code | Meaning | Example |
|-----------|---------|---------|
| 0 | Success (including empty result set) | No runs found for URL filter |
| 1 | Usage error | Unknown subcommand, invalid flag, bad `--run-id` format |
| 2 | Connection/infrastructure error | Database unreachable, table missing, PostgreSQL detected |
| 3 | Data error | `--run-id` not found, corrupt profile blob |

**JSON error envelope (when format is JSON):**
```json
{"error": {"code": 2, "message": "XHGui database not found. Run 'ddev xhgui on' to enable profiling."}}
```

Empty results return exit 0 with valid JSON on stdout. This is critical -- agents must not fail when a query legitimately returns nothing.

### Edge Cases

- **Corrupt profile blob:** Skip with warning to stderr, do not crash
- **Gzip-compressed blobs:** Migration edge case only (not produced by DDEV's PDO backend). Detect by first 2 bytes (`\x1f\x8b`), decompress with `gzdecode()`, then `json_decode()`. If decode fails after decompression, treat as corrupt.
- **PostgreSQL backend:** Detect via `$DDEV_DATABASE_FAMILY`, exit 2 with message
- **XHGui not enabled:** Check for `results` table existence before querying. Detect `xhgui` database missing (PDO error code `1049`) separately.
- **Very large profiles:** Set `ini_set('memory_limit', '256M')` at script startup. Pre-check `LENGTH(profile)` before fetching blobs > 20MB (warn on stderr).
- **Missing metric keys in profile entries:** Default to 0 if `cpu`, `mu`, or `pmu` is absent
- **MariaDB compatibility:** DDEV defaults to MariaDB, not MySQL. Both share `DDEV_DATABASE_FAMILY=mysql`. All SQL is standard and compatible with both.
- **LIKE wildcard characters in `--url`:** Escape `%` and `_` in user input with `ESCAPE '\\'` clause

## File Structure

```
ddev-xhgui-cli/
├── install.yaml                    # Addon manifest
├── .gitattributes                  # Force LF line endings for shell scripts
├── commands/
│   └── web/
│       └── xhgui-query             # Bash web command (entry point)
├── xhgui-cli/
│   └── query.php                   # PHP query/formatting logic
├── tests/
│   ├── test.bats                   # Bats integration tests (DDEV end-to-end)
│   └── php/
│       ├── ExclusiveTimeTest.php   # Unit test: exclusive time calculation
│       ├── InputValidationTest.php # Unit test: argument validation & allowlists
│       └── FormattingTest.php      # Unit test: table/JSON output formatting
├── test-project/                   # Local DDEV test project (gitignored)
├── .github/
│   └── workflows/
│       └── tests.yml               # CI via ddev/github-action-add-on-test@v2
├── README.md
└── LICENSE
```

### `install.yaml`

```yaml
name: xhgui-cli
ddev_version_constraint: ">= v1.24.4"
dependencies: []
project_files:
  - commands/web/xhgui-query
  - xhgui-cli/query.php
global_files: []
pre_install_actions: []
post_install_actions:
  - chmod +x ${DDEV_APPROOT}/.ddev/commands/web/xhgui-query
removal_actions:
  - rmdir ${DDEV_APPROOT}/.ddev/xhgui-cli 2>/dev/null || true
```

### `commands/web/xhgui-query` (skeleton)

```bash
#!/usr/bin/env bash
#ddev-generated
## Description: Query XHGui profiling data from the CLI
## Usage: xhgui-query <subcommand> [flags]
## Example: "ddev xhgui-query runs --limit 10"
## ExecRaw: true

set -eu -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_SCRIPT="${SCRIPT_DIR}/../../xhgui-cli/query.php"

if [ ! -f "$PHP_SCRIPT" ]; then
  echo "Error: query.php not found at $PHP_SCRIPT" >&2
  echo "Try reinstalling: ddev add-on get owner/ddev-xhgui-cli" >&2
  exit 2
fi

# TTY detection passed as env var (not flag) to keep $argv user-facing only
export XHGUI_IS_TTY=0
[ -t 1 ] && XHGUI_IS_TTY=1

# exec replaces this shell -- PHP exit code becomes the command exit code
exec php "$PHP_SCRIPT" "$@"
```

### `xhgui-cli/query.php` (responsibilities)

- Set `ini_set('memory_limit', '256M')` at startup
- Parse CLI arguments via native `getopt()` (zero Composer dependencies)
- Read `XHGUI_IS_TTY` env var for format auto-detection
- Connect to MySQL via PDO with `ATTR_ERRMODE => ERRMODE_EXCEPTION` and `ATTR_EMULATE_PREPARES => false`
- Validate `DDEV_DATABASE_FAMILY` is MySQL
- Check `results` table exists
- Validate all input: `--sort` via allowlist, `--limit` via `(int)` cast + range, `--run-id` via hex regex, `--format` via allowlist
- Execute subcommand-specific SQL with explicit column lists (never `SELECT *`)
- For `top-functions`: deserialize profile JSON, compute exclusive times via hash map
- Format output as table or JSON
- Return appropriate exit codes with JSON error envelope in JSON mode

### `.github/workflows/tests.yml` (CI)

```yaml
name: tests
on:
  pull_request:
  push:
    branches: [main]
  schedule:
    - cron: "25 08 * * *"
  workflow_dispatch:
    inputs:
      debug_enabled:
        type: boolean
        description: Debug with tmate
        default: false

permissions:
  contents: read

jobs:
  php-unit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.1"
      - run: |
          for f in tests/php/*.php; do
            echo "Running $f..."
            php "$f"
          done

  integration:
    strategy:
      matrix:
        ddev_version: [stable, HEAD]
      fail-fast: false
    runs-on: ubuntu-latest
    steps:
      - uses: ddev/github-action-add-on-test@v2
        with:
          ddev_version: ${{ matrix.ddev_version }}
          token: ${{ secrets.GITHUB_TOKEN }}
          debug_enabled: ${{ github.event.inputs.debug_enabled }}
          addon_repository: ${{ env.GITHUB_REPOSITORY }}
          addon_ref: ${{ env.GITHUB_REF }}
```

## Implementation Phases

### Phase 1: Project Scaffolding

- [ ] Initialize git repository with addon structure from ddev-addon-template
- [ ] Write `install.yaml` manifest with `ddev_version_constraint: ">= v1.24.4"`
- [ ] Add `.gitattributes` with `commands/web/* text eol=lf`
- [ ] Create bash web command with TTY detection via env var
- [ ] Create PHP script skeleton: `getopt()` argument parsing, PDO connection with `EMULATE_PREPARES => false`, database/table validation, `memory_limit` set
- [ ] Add CI workflow: `php-unit` job (PHP 8.1, runs `tests/php/*.php`) + `integration` job (`ddev/github-action-add-on-test@v2`)
- [ ] Create `tests/setup-local-test.sh` for local development verification
- [ ] Verify addon installs into a local test DDEV project from local path

### Phase 2: Core Commands

- [ ] Implement `runs` subcommand: SQL query on denormalized columns with explicit column list, `--sort` via allowlist mapping, `--limit` via `PDO::PARAM_INT`, `--url` via LIKE with escaped wildcards and `ESCAPE '\\'` clause, table + JSON output
- [ ] Implement `top-functions` subcommand: profile blob fetch with `LENGTH()` pre-check, JSON decode, O(E) hash map for exclusive time, negative value floor at 0, sorting, table + JSON output
- [ ] Write PHP unit tests for exclusive time calculation and input validation (run locally without DDEV)

### Phase 3: Polish and Error Handling

- [ ] Input validation: `--run-id` regex `/^[0-9a-f]{24}$/i`, `--limit` range [1,1000], `--sort` and `--format` allowlists
- [ ] JSON error envelope on stdout when format is JSON + error occurs
- [ ] Empty results: valid JSON on stdout, helpful message on stderr
- [ ] Invalid `--run-id` error with suggestion to run `ddev xhgui-query runs`
- [ ] Human-readable formatting: microseconds to milliseconds, bytes to MB
- [ ] Corrupt/gzip profile blob detection and graceful handling (gzip is migration edge case only)
- [ ] PostgreSQL detection with clear unsupported message
- [ ] XHGui-not-enabled detection (missing `results` table or `xhgui` database)
- [ ] PDO exceptions caught with generic messages (no leaking connection details)
- [ ] Strip non-printable characters from table output

### Phase 4: Testing

#### Local DDEV Test Setup

During development, use a local test project to verify commands against real profiling data:

- [ ] Create `test-project/` directory (gitignored) with a minimal DDEV PHP project
- [ ] Script: `tests/setup-local-test.sh` that automates:
  1. `ddev config --project-name xhgui-cli-test --project-type php`
  2. `ddev add-on get ddev/ddev-xhgui` (installs XHGui with profiling)
  3. `ddev add-on get .` (installs this addon from local source)
  4. `ddev start`
  5. Creates a simple `index.php` that does some work (DB queries, loops) to generate non-trivial profiles
  6. Hits the page via `curl` to generate profiling data
  7. Polls the database until profile data exists
- [ ] Script: `tests/teardown-local-test.sh` — `ddev stop --remove-data` and cleanup
- [ ] Add `test-project/` to `.gitignore`

#### PHP Unit Tests (no DDEV required)

Test pure PHP logic in isolation without needing a running DDEV environment. Uses PHP's built-in `assert()` — no PHPUnit dependency needed:

- [ ] `tests/php/ExclusiveTimeTest.php`: Tests the O(E) exclusive time calculation
  - Known profile blob with pre-calculated expected exclusive times
  - Edge case: single function (`main()` only)
  - Edge case: negative exclusive time floors to 0
  - Edge case: function appears as both caller and callee in different edges
- [ ] `tests/php/InputValidationTest.php`: Tests argument validation
  - `--run-id` regex accepts valid 24-char hex, rejects others
  - `--sort` allowlist rejects unknown values
  - `--limit` range validation [1, 1000]
  - `--url` wildcard escaping (`%` and `_` escaped properly)
- [ ] `tests/php/FormattingTest.php`: Tests output formatting
  - Microseconds → milliseconds conversion
  - Bytes → MB conversion
  - JSON output is valid and matches expected schema
  - Table output column alignment with known input data
- [ ] Run PHP unit tests locally: `php tests/php/ExclusiveTimeTest.php` (each file is self-contained and runnable)

#### Bats Integration Tests (end-to-end in CI)

- [ ] Bats test: addon installs successfully
- [ ] Bats test: `runs` returns results after profiling
- [ ] Bats test: `runs --url` filters correctly
- [ ] Bats test: `runs --format json` piped output is valid parseable JSON (no stderr mixed in)
- [ ] Bats test: `top-functions` parses profile and shows exclusive times
- [ ] Bats test: `top-functions --run-id` targets specific run
- [ ] Bats test: unknown subcommand exits 1 with usage message
- [ ] Bats test: empty results exit 0 with valid JSON
- [ ] Use database polling (not `sleep`) in test setup to wait for profile data

### Phase 5: Documentation and Release

- [ ] README: installation, quick start, command reference, JSON output examples
- [ ] README: requirements (DDEV >= 1.24.4, MySQL/MariaDB backend)
- [ ] README: known limitations (MySQL/MariaDB only, no aggregation, no flame charts)
- [ ] README: note that JSON output schemas are stable within major versions
- [ ] Add `ddev-get` topic to GitHub repo for registry listing
- [ ] Tag v0.1.0 release

## Acceptance Criteria

- [ ] `ddev add-on get /path/to/ddev-xhgui-cli` installs without errors
- [ ] `ddev xhgui-query runs` lists profiling runs with wall time, CPU, peak memory
- [ ] `ddev xhgui-query runs --url /shop` filters runs by URL substring
- [ ] `ddev xhgui-query runs --sort wt` sorts by wall time descending
- [ ] `ddev xhgui-query top-functions` shows function-level exclusive wall time breakdown
- [ ] `ddev xhgui-query top-functions --run-id <id>` targets a specific run
- [ ] JSON output produced when stdout is piped; ASCII table when TTY
- [ ] Exit codes follow the documented contract (0/1/2/3)
- [ ] All 8 bats tests pass in CI (stable + HEAD matrix)
- [ ] Addon removal cleans up installed files and empty directory

## Scope Exclusions

Not in v0.1.0:
- `compare` subcommand (deferred to v0.2.0)
- `export` subcommand (deferred to v0.2.0)
- PostgreSQL support
- Watch/alert functionality
- URL-based function aggregation across multiple runs (`top-functions --url /shop/products` to see slowest functions across all requests to that URL)
- Aggregation across runs (averages, percentiles)
- Callgraph visualization (flame charts)
- Profile data cleanup/purge commands
- CI/CD pipeline integration helpers
- `--since` / `--after` time filter on `runs`
- `--quiet` flag for suppressing informational stderr
- `--version` flag

## Dependencies and Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| XHGui schema changes in future DDEV versions | Low | High | Pin to known schema; `ddev_version_constraint` in install.yaml |
| Large profile blobs cause PHP memory issues | Low | Medium | `ini_set('memory_limit', '256M')`; `LENGTH()` pre-check with warning |
| DDEV addon API changes | Low | Medium | `ddev_version_constraint` in install.yaml |
| Profile format varies (gzip vs JSON) | Low | Low | Gzip only from MongoDB migration; detect header bytes, handle gracefully |
| SQL injection via `--sort` column names | Medium | High | Hardcoded allowlist mapping; never interpolate user input into ORDER BY |
| PHP `LIMIT` parameterization on MariaDB | Low | Low | `ATTR_EMULATE_PREPARES => false` ensures correct integer binding |

## References

### Research Documents
- `/Users/joshuapease/Development/_personal-os/work-log/2026/03-march/week-09/ddev-xhgui-cli-research/ddev-xhgui-cli-research.md`
- `/Users/joshuapease/Development/_personal-os/work-log/2026/03-march/week-09/ddev-xhgui-cli-research/2026-03-02-feat-ddev-xhgui-cli-addon-plan.md`
- `/Users/joshuapease/Development/_personal-os/work-log/2026/03-march/week-09/ddev-xhgui-cli-research/xhgui-schema-and-data-format.md`
- `/Users/joshuapease/Development/_personal-os/work-log/2026/03-march/week-09/ddev-xhgui-cli-research/ddev-addon-development.md`
- `/Users/joshuapease/Development/_personal-os/work-log/2026/03-march/week-09/ddev-xhgui-cli-research/repository-research-summary.md`

### External
- DDEV XHGui docs: https://docs.ddev.com/en/stable/users/debugging-profiling/xhprof-profiling/
- DDEV addon template: https://github.com/ddev/ddev-addon-template
- DDEV custom commands: https://docs.ddev.com/en/stable/users/extend/custom-commands/
- DDEV addon CI action: https://github.com/ddev/github-action-add-on-test
- perftools/xhgui: https://github.com/perftools/xhgui
- CLI Interface Guidelines: https://clig.dev/
- PHP getopt() Manual: https://www.php.net/manual/en/function.getopt.php
