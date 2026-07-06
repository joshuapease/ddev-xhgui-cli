# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
JSON schemas, flag names, and envelope error codes may still change before 1.0.0.

## [Unreleased]

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

[Unreleased]: https://github.com/joshuapease/ddev-xhgui-cli/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/joshuapease/ddev-xhgui-cli/releases/tag/v0.1.0
