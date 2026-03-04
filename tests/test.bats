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

health_checks() {
  ddev exec "curl -s https://localhost" >/dev/null
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

  # Enable profiling and hit the page
  ddev xhgui on >/dev/null 2>&1
  ddev exec "curl -s https://localhost" >/dev/null

  # Wait for profile data to appear
  wait_for_profile_data
}

@test "addon installs successfully" {
  cd "$TESTDIR"
  echo "# ddev add-on get $DIR with project $PROJNAME in $TESTDIR ($(pwd))" >&3
  ddev add-on get "$DIR"
  ddev restart >/dev/null

  # Verify command is available
  run ddev xhgui-query --help
  [ "$status" -eq 0 ]
  [[ "$output" == *"subcommand"* ]]
}

@test "runs returns results after profiling" {
  cd "$TESTDIR"
  ddev add-on get "$DIR" >/dev/null
  ddev restart >/dev/null
  generate_profile_data

  run ddev xhgui-query runs
  [ "$status" -eq 0 ]
  [[ "$output" == *"WALL (ms)"* ]]
}

@test "runs --url filters correctly" {
  cd "$TESTDIR"
  ddev add-on get "$DIR" >/dev/null
  ddev restart >/dev/null
  generate_profile_data

  # Filter for a URL that exists
  run ddev xhgui-query runs --url /
  [ "$status" -eq 0 ]

  # Filter for a URL that does not exist
  run ddev xhgui-query runs --url /nonexistent-path-xyz
  [ "$status" -eq 0 ]
}

@test "runs --format json piped output is valid JSON" {
  cd "$TESTDIR"
  ddev add-on get "$DIR" >/dev/null
  ddev restart >/dev/null
  generate_profile_data

  local json_output
  json_output=$(ddev xhgui-query runs --format json)

  # Validate it's parseable JSON
  echo "$json_output" | python3 -m json.tool >/dev/null 2>&1
  [ $? -eq 0 ]

  # Verify it has expected fields
  echo "$json_output" | python3 -c "
import json, sys
data = json.load(sys.stdin)
assert isinstance(data, list), 'Expected array'
if len(data) > 0:
    assert 'wall_time_us' in data[0], 'Missing wall_time_us'
    assert 'cpu_time_us' in data[0], 'Missing cpu_time_us'
    assert 'timestamp' in data[0], 'Missing timestamp'
"
}

@test "top-functions parses profile and shows exclusive times" {
  cd "$TESTDIR"
  ddev add-on get "$DIR" >/dev/null
  ddev restart >/dev/null
  generate_profile_data

  run ddev xhgui-query top-functions
  [ "$status" -eq 0 ]
  [[ "$output" == *"EXCL WALL (ms)"* ]]
  [[ "$output" == *"FUNCTION"* ]]
}

@test "top-functions --run-id targets specific run" {
  cd "$TESTDIR"
  ddev add-on get "$DIR" >/dev/null
  ddev restart >/dev/null
  generate_profile_data

  # Get a run ID from JSON output
  local run_id
  run_id=$(ddev xhgui-query runs --format json --limit 1 | python3 -c "import json,sys; print(json.load(sys.stdin)[0]['id'])")

  run ddev xhgui-query top-functions --run-id "$run_id" --format json
  [ "$status" -eq 0 ]

  echo "$output" | python3 -c "
import json, sys
data = json.load(sys.stdin)
assert 'run_id' in data, 'Missing run_id'
assert 'functions' in data, 'Missing functions'
assert len(data['functions']) > 0, 'Expected at least one function'
"
}

@test "unknown subcommand exits 1 with usage message" {
  cd "$TESTDIR"
  ddev add-on get "$DIR" >/dev/null
  ddev restart >/dev/null

  run ddev xhgui-query notreal
  [ "$status" -eq 1 ]
  [[ "$output" == *"Unknown subcommand"* ]]
}

@test "empty results exit 0 with valid JSON" {
  cd "$TESTDIR"
  ddev add-on get "$DIR" >/dev/null
  ddev restart >/dev/null

  # Enable xhgui to create the database/table but don't generate data
  ddev xhgui on >/dev/null 2>&1
  sleep 2

  run ddev xhgui-query runs --format json --url /this-url-definitely-does-not-exist
  [ "$status" -eq 0 ]

  # stdout should be valid JSON (empty array)
  echo "$output" | python3 -c "
import json, sys
data = json.load(sys.stdin)
assert isinstance(data, list), 'Expected array'
assert len(data) == 0, 'Expected empty array'
"
}
