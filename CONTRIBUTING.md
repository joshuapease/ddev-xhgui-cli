# Contributing

Thanks for your interest. This is a small, deliberately dependency-free project:
one PHP file, one bash wrapper, two test suites.

## Layout

- `commands/web/xhgui-query` — bash DDEV web command: detects TTY, sets
  `XHGUI_IS_TTY`, and `exec`s into PHP
- `xhgui-cli/query.php` — all logic: arg parsing, SQL, exclusive-time
  computation, output formatting
- `install.yaml` — DDEV add-on manifest

## Constraints to keep in mind

- **PHP 7.1 floor.** `query.php` runs on the project's own PHP version inside
  the web container, so no syntax newer than 7.1. CI lints and tests on 7.1
  and 8.4.
- **No dependencies.** No Composer, no PHPUnit. Unit tests are plain PHP files
  with a small assert harness (`tests/php/bootstrap.php`).
- **Output contract.** stdout is data, stderr is commentary. In JSON mode
  stdout must always parse. Failure classes (1 usage, 2 infrastructure,
  3 data) live in the JSON error envelope — the `ddev` host command collapses
  exit codes to 0/1, so never rely on `$?` for the class.

## Running tests

PHP unit tests (fast, no DDEV required):

```bash
for f in tests/php/*.php; do php "$f"; done
```

Integration tests (slow, require DDEV and [bats](https://bats-core.readthedocs.io/)):

```bash
bats tests/test.bats
```

Each integration test creates a temporary DDEV project, installs the add-on,
and asserts against real profiling data.

For a persistent local sandbox with real profile data:

```bash
bash tests/setup-local-test.sh    # creates test-project/ with the add-on installed
bash tests/teardown-local-test.sh # removes it
```

## Submitting changes

- Branch off `main` and open a pull request. CI runs the unit suite on PHP 7.1
  and 8.4 and the integration suite against DDEV stable and HEAD.
- Behavior changes need a test. Contract changes (JSON schema, flags, error
  codes) also need a README update — the command reference is kept exactly in
  sync with the code.
