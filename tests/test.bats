#!/usr/bin/env bats

setup() {
  set -eu -o pipefail
  export DIR="$( cd "$( dirname "$BATS_TEST_FILENAME" )" >/dev/null 2>&1 && pwd )/.."
  export TESTDIR=~/tmp/test-xhgui-cli
  mkdir -p "$TESTDIR"
  export PROJNAME=test-xhgui-cli
  export DDEV_NON_INTERACTIVE=true
  ddev delete -Oy "$PROJNAME" >/dev/null 2>&1 || true
  cd "$TESTDIR"
  ddev config --project-name="$PROJNAME" --project-type=php
  ddev start -y >/dev/null
}

teardown() {
  set -eu -o pipefail
  cd "$TESTDIR" || true
  ddev delete -Oy "$PROJNAME" >/dev/null 2>&1
  [ "$TESTDIR" != "" ] && rm -rf "$TESTDIR"
}

install_addon() {
  ddev add-on get "$DIR" >/dev/null
  ddev restart >/dev/null
}

wait_for_profile_data() {
  local max_attempts=30
  local attempt=0
  while [ $attempt -lt $max_attempts ]; do
    local count
    count=$(ddev mysql -udb -pdb xhgui -N -e "SELECT COUNT(*) FROM results" 2>/dev/null || echo "0")
    if [ "$count" -gt 0 ] 2>/dev/null; then
      return 0
    fi
    sleep 1
    attempt=$((attempt + 1))
  done
  return 1
}

generate_profile_data() {
  # Create a PHP page that does some work
  cat > "$TESTDIR/index.php" <<'PHPEOF'
<?php
$sum = 0;
for ($i = 0; $i < 10000; $i++) {
    $sum += sqrt($i);
}
echo "Hello from xhgui-cli test. Sum: $sum";
PHPEOF

  # Enable profiling (must be done after restart since restart disables it)
  ddev xhgui on >/dev/null 2>&1

  # Hit the page to generate data
  ddev exec "curl -s https://localhost" >/dev/null

  # Wait for profile data to appear
  wait_for_profile_data
}

@test "addon installs successfully" {
  cd "$TESTDIR"
  echo "# ddev add-on get $DIR with project $PROJNAME in $TESTDIR ($(pwd))" >&3
  install_addon

  # Verify command is available
  run ddev xhgui-query --help
  [ "$status" -eq 0 ]
  [[ "$output" == *"subcommand"* ]]

  # Version flag reports the tool version
  run ddev xhgui-query --version
  [ "$status" -eq 0 ]
  [[ "$output" == xhgui-cli* ]]
}

@test "addon removes cleanly" {
  cd "$TESTDIR"
  install_addon

  # Both installed files exist before removal
  [ -f "$TESTDIR/.ddev/commands/web/xhgui-query" ]
  [ -f "$TESTDIR/.ddev/xhgui-cli/query.php" ]

  ddev add-on remove xhgui-cli

  # Files and the xhgui-cli directory are gone
  [ ! -f "$TESTDIR/.ddev/commands/web/xhgui-query" ]
  [ ! -f "$TESTDIR/.ddev/xhgui-cli/query.php" ]
  [ ! -d "$TESTDIR/.ddev/xhgui-cli" ]
}

@test "runs returns results after profiling" {
  cd "$TESTDIR"
  install_addon
  generate_profile_data

  run ddev xhgui-query runs --format table
  [ "$status" -eq 0 ]
  [[ "$output" == *"WALL (ms)"* ]]
}

@test "runs --url filters correctly" {
  cd "$TESTDIR"
  install_addon
  generate_profile_data

  # Filter for a URL that exists
  run ddev xhgui-query runs --url / --format json
  [ "$status" -eq 0 ]

  # Filter for a URL that does not exist — should still succeed with empty results
  run ddev xhgui-query runs --url /nonexistent-path-xyz --format json
  [ "$status" -eq 0 ]
}

@test "runs --format json piped output is valid JSON" {
  cd "$TESTDIR"
  install_addon
  generate_profile_data

  # --format=json exercises the =-syntax on the success path
  local json_output
  json_output=$(ddev xhgui-query runs --format=json)

  # Validate it's parseable JSON using php (available in all environments)
  echo "$json_output" | php -r '
    $data = json_decode(file_get_contents("php://stdin"), true);
    if (!is_array($data)) { echo "INVALID JSON\n"; exit(1); }
    if (count($data) > 0) {
      if (!isset($data[0]["wall_time_us"])) { echo "Missing wall_time_us\n"; exit(1); }
      if (!isset($data[0]["cpu_time_us"])) { echo "Missing cpu_time_us\n"; exit(1); }
      if (!isset($data[0]["timestamp"])) { echo "Missing timestamp\n"; exit(1); }
    }
    echo "VALID\n";
  '
}

@test "top-functions parses profile and shows exclusive times" {
  cd "$TESTDIR"
  install_addon
  generate_profile_data

  run ddev xhgui-query top-functions --format table
  [ "$status" -eq 0 ]
  [[ "$output" == *"EXCL WALL (ms)"* ]]
  [[ "$output" == *"FUNCTION"* ]]
}

@test "top-functions --run-id targets specific run" {
  cd "$TESTDIR"
  install_addon
  generate_profile_data

  # Get a run ID from JSON output using php
  local run_id
  run_id=$(ddev xhgui-query runs --format json --limit 1 | php -r '
    $data = json_decode(file_get_contents("php://stdin"), true);
    echo $data[0]["id"];
  ')

  run ddev xhgui-query top-functions --run-id "$run_id" --format json
  [ "$status" -eq 0 ]

  echo "$output" | php -r '
    $data = json_decode(file_get_contents("php://stdin"), true);
    if (!isset($data["run_id"])) { echo "Missing run_id\n"; exit(1); }
    if (!isset($data["functions"])) { echo "Missing functions\n"; exit(1); }
    if (count($data["functions"]) === 0) { echo "Expected at least one function\n"; exit(1); }
    echo "VALID\n";
  '
}

@test "unknown subcommand exits 1 with usage message" {
  cd "$TESTDIR"
  install_addon

  run ddev xhgui-query notreal
  [ "$status" -eq 1 ]
  [[ "$output" == *"Unknown subcommand"* ]]

  # Unknown flags are also usage errors, not silently ignored
  run ddev xhgui-query runs --bogus 5
  [ "$status" -eq 1 ]
  [[ "$output" == *"Unknown flag '--bogus'"* ]]

  # --flag=value syntax is accepted end-to-end. ddev collapses any nonzero
  # exit from a custom command to 1 at the host level, so assert on the JSON
  # envelope, not $status: no xhgui DB exists in this test, so a correctly
  # parsed --limit=5 reaches the DB step and reports code 2 (infra), not a
  # code 1 usage error.
  run ddev xhgui-query runs --limit=5
  [[ "$output" != *"Unknown flag"* ]]
  [[ "$output" != *"requires a value"* ]]
  [[ "$output" == *'"code": 2'* ]]
}

@test "empty results exit 0 with valid JSON" {
  cd "$TESTDIR"
  install_addon

  # Enable xhgui to create the database/table but don't generate data
  ddev xhgui on >/dev/null 2>&1
  sleep 2

  # Capture stdout separately (stderr has informational message)
  local json_output
  json_output=$(ddev xhgui-query runs --format json --url /this-url-definitely-does-not-exist)

  # stdout should be valid JSON (empty array)
  echo "$json_output" | php -r '
    $data = json_decode(file_get_contents("php://stdin"), true);
    if (!is_array($data)) { echo "INVALID JSON\n"; exit(1); }
    if (count($data) !== 0) { echo "Expected empty array\n"; exit(1); }
    echo "VALID\n";
  '
}
