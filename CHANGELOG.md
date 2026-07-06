# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
JSON schemas, flag names, and envelope error codes may still change before 1.0.0.

## [Unreleased]

## [0.1.1] - 2026-07-06

### Added

- `ddev xhgui-query callers --function <name>` — show which functions call a
  given function, with per-caller call counts, wall/CPU/memory attribution, and
  each caller's share of the function's inclusive total. Closes the drill-down
  gap for hot functions that can't be grepped (internal functions like
  `PDO::query`, closures, vendor methods). A function absent from the run's
  profile reports data error class 3 in the JSON envelope

### Fixed

- The tool no longer profiles itself. With profiling enabled, DDEV's xhprof
  prepend hooks CLI PHP too, so every `ddev xhgui-query` invocation was being
  recorded as a run — hijacking the "most recent run" default from the request
  actually being investigated. The wrapper now blanks `auto_prepend_file` for
  the tool's own process

## [0.1.0] - 2026-07-06

Initial release.

### Added

- `ddev xhgui-query runs` — list recent profiling runs with wall time, CPU, and
  peak memory; filter by URL substring; sort by `time`, `wt`, `cpu`, or `pmu`
- `ddev xhgui-query top-functions` — function-level exclusive-time breakdown for
  one run, computed from XHProf's `caller==>callee` edge map
- TTY-aware output: tables in a terminal, JSON on a pipe; `--format table|json`
  overrides
- Machine-readable failure contract: in JSON mode stdout always parses; failures
  emit `{"error": {"code": N, "message": "..."}}` where N is 1 (usage),
  2 (infrastructure), or 3 (data). The `ddev` host command itself exits 0 or 1,
  so consumers read the class from `error.code`
- `--flag value` and `--flag=value` syntax; unknown flags, bare arguments, and
  missing values are usage errors
- `--version` flag
- Clean install/remove lifecycle via `ddev add-on get` / `ddev add-on remove`
- Runs on the project's own PHP (7.1+) in the web container; MySQL/MariaDB only

[Unreleased]: https://github.com/joshuapease/ddev-xhgui-cli/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/joshuapease/ddev-xhgui-cli/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/joshuapease/ddev-xhgui-cli/releases/tag/v0.1.0
