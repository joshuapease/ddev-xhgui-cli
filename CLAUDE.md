# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

DDEV add-on that provides a CLI for querying XHGui profiling data. Two subcommands: `runs` (list profiling runs) and `top-functions` (function-level exclusive time breakdown). Output auto-detects TTY for table vs JSON format.

## Architecture

- `commands/web/xhgui-query` — Bash entrypoint registered as a DDEV web command. Detects TTY, sets `XHGUI_IS_TTY` env var, then `exec`s into the PHP script.
- `xhgui-cli/query.php` — Single-file PHP tool containing all logic: arg parsing, DB queries, exclusive time computation, and output formatting. No external dependencies beyond PDO/MySQL.
- Profile data lives in the `xhgui.results` MySQL table. The `profile` column stores JSON (possibly gzipped) with XHProf `caller==>callee` keyed metrics.

### Key algorithm

`computeExclusiveTimes()` computes exclusive times from XHProf's caller==>callee format: inclusive totals are accumulated per function, then children's inclusive times are subtracted. Negative values are floored to 0 (XHProf shared-callee limitation).

### Testing guard

`define('XHGUI_CLI_TESTING', true)` before requiring `query.php` to skip main execution and expose functions for unit testing.

## Testing

### PHP unit tests (no dependencies, fast)
```bash
php tests/php/ExclusiveTimeTest.php
php tests/php/FormattingTest.php
php tests/php/InputValidationTest.php

# Run all PHP tests
for f in tests/php/*.php; do php "$f"; done
```

### Integration tests (requires DDEV, slow)
```bash
bats tests/test.bats
```

Integration tests create a temporary DDEV project, install the add-on, generate profile data, and run assertions. They require DDEV to be installed and running.

## Exit Codes

- 0: Success (including empty results)
- 1: Usage error (invalid flag/subcommand)
- 2: Infrastructure error (DB unreachable)
- 3: Data error (run ID not found)

## Conventions

- PHP uses manual `--flag value` arg parsing (not `getopt()`) for BSD compatibility
- Errors go to STDERR; structured JSON errors also echo to STDOUT when format is JSON
- The bash wrapper uses `ExecRaw: true` so PHP's exit code becomes the DDEV command exit code
- MySQL/MariaDB only — no PostgreSQL support
