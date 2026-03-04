# ddev-xhgui-cli

Query [XHGui](https://github.com/perftools/xhgui) profiling data from the terminal. Works with DDEV's built-in XHGui integration.

## Installation

```bash
ddev add-on get owner/ddev-xhgui-cli
ddev restart
```

Requires DDEV >= 1.24.4 with MySQL or MariaDB.

## Quick Start

```bash
# Enable profiling and visit pages
ddev xhgui on
curl https://yourproject.ddev.site

# List recent profiling runs
ddev xhgui-query runs

# See which functions are slowest
ddev xhgui-query top-functions
```

## Commands

### `ddev xhgui-query runs`

List recent profiling runs.

```bash
ddev xhgui-query runs [--limit 20] [--url /path] [--sort time|wt|cpu|pmu] [--format table|json]
```

| Flag       | Default | Description                                                   |
| ---------- | ------- | ------------------------------------------------------------- |
| `--limit`  | 20      | Number of runs (max 1000)                                     |
| `--url`    | (none)  | Filter by URL substring                                       |
| `--sort`   | `time`  | Sort by: `time`, `wt` (wall time), `cpu`, `pmu` (peak memory) |
| `--format` | auto    | `table` when TTY, `json` when piped                           |

**Table output:**

```
RUN ID      URL                  WALL (ms)  CPU (ms)  PEAK MEM (MB)  DATE
abc123def   /shop/products       450.1      120.3     8.0            2026-03-04 14:23
def456ghi   /shop/cart           280.5       95.1     6.2            2026-03-04 14:22
```

**JSON output:**

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

### `ddev xhgui-query top-functions`

Show function-level exclusive time breakdown.

```bash
ddev xhgui-query top-functions [--run-id ID] [--limit 10] [--sort wt|cpu|pmu] [--format table|json]
```

| Flag       | Default  | Description                           |
| ---------- | -------- | ------------------------------------- |
| `--run-id` | (latest) | Target a specific run (24-char hex)   |
| `--limit`  | 10       | Number of functions (max 1000)        |
| `--sort`   | `wt`     | Sort by exclusive: `wt`, `cpu`, `pmu` |
| `--format` | auto     | `table` when TTY, `json` when piped   |

**Table output:**

```
FUNCTION                     CALLS  EXCL WALL (ms)  INCL WALL (ms)  EXCL CPU (ms)  EXCL MEM (MB)
PDO::query                   47     82.0            85.0            1.8            0.4
Twig\Template::render        12     45.3            120.5           44.1           2.1
```

**JSON output:**

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

## Agent and CI Usage

Output format auto-detects based on TTY. When piped, JSON is produced:

```bash
# Get the slowest endpoint
ddev xhgui-query runs --sort wt --limit 1 | jq '.[0].url'

# Analyze a specific run
RUN_ID=$(ddev xhgui-query runs --limit 1 | jq -r '.[0].id')
ddev xhgui-query top-functions --run-id "$RUN_ID" | jq '.functions[:5]'

# Compare two URLs
ddev xhgui-query runs --url /page-a --limit 1 | jq '.[0].wall_time_us'
ddev xhgui-query runs --url /page-b --limit 1 | jq '.[0].wall_time_us'
```

## Exit Codes

| Code | Meaning                           | Example                    |
| ---- | --------------------------------- | -------------------------- |
| 0    | Success (including empty results) | No runs match filter       |
| 1    | Usage error                       | Invalid flag or subcommand |
| 2    | Infrastructure error              | Database unreachable       |
| 3    | Data error                        | Run ID not found           |

## Requirements

- DDEV >= 1.24.4
- MySQL or MariaDB (DDEV default)
- XHGui enabled (`ddev xhgui on`)

## Known Limitations

- MySQL/MariaDB only (no PostgreSQL)
- No aggregation across runs (averages, percentiles)
- No flame chart visualization
- JSON output schemas are stable within major versions

## License

MIT
