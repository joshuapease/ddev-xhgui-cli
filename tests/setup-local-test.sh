#!/usr/bin/env bash
# Sets up a local DDEV test project for manual verification of xhgui-cli commands.
# Usage: bash tests/setup-local-test.sh

set -eu -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADDON_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
TEST_DIR="$ADDON_DIR/test-project"
PROJECT_NAME="xhgui-cli-test"

echo "==> Setting up test project in $TEST_DIR"
mkdir -p "$TEST_DIR"
cd "$TEST_DIR"

# Configure DDEV project
if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name="$PROJECT_NAME" --project-type=php
fi

# Create a PHP page that generates interesting profiles
cat > index.php <<'PHPEOF'
<?php
// Simulates a realistic request with DB, string, and math operations

function heavy_computation() {
    $result = 0;
    for ($i = 0; $i < 50000; $i++) {
        $result += sqrt($i) * log($i + 1);
    }
    return $result;
}

function string_processing() {
    $text = str_repeat("The quick brown fox jumps over the lazy dog. ", 1000);
    $words = explode(' ', $text);
    sort($words);
    return count($words);
}

function nested_calls() {
    $a = heavy_computation();
    $b = string_processing();
    return $a + $b;
}

$start = microtime(true);
$result = nested_calls();
$elapsed = (microtime(true) - $start) * 1000;

echo "<!DOCTYPE html><html><body>";
echo "<h1>XHGui CLI Test Page</h1>";
echo "<p>Result: $result</p>";
echo "<p>Elapsed: " . number_format($elapsed, 2) . " ms</p>";
echo "</body></html>";
PHPEOF

# Start DDEV
ddev start

# Enable XHGui profiling
ddev xhgui on

# Install the addon from local source
ddev add-on get "$ADDON_DIR"
ddev restart

# Re-enable XHGui after restart (restart disables it)
ddev xhgui on

echo ""
echo "==> Generating profiling data..."

# Hit the page multiple times to generate varied profiles
for i in $(seq 1 5); do
  ddev exec "curl -s https://localhost" > /dev/null
  echo "  Request $i sent"
done

# Wait for profile data
echo "==> Waiting for profile data..."
MAX_ATTEMPTS=30
ATTEMPT=0
while [ $ATTEMPT -lt $MAX_ATTEMPTS ]; do
  COUNT=$(ddev mysql -udb -pdb xhgui -N -e "SELECT COUNT(*) FROM results" 2>/dev/null || echo "0")
  if [ "$COUNT" -gt 0 ] 2>/dev/null; then
    echo "  Found $COUNT profiling run(s)"
    break
  fi
  sleep 1
  ATTEMPT=$((ATTEMPT + 1))
done

if [ $ATTEMPT -eq $MAX_ATTEMPTS ]; then
  echo "  WARNING: No profile data found after ${MAX_ATTEMPTS}s. XHGui may not be enabled."
fi

echo ""
echo "==> Setup complete! Try these commands:"
echo ""
echo "  ddev xhgui-query runs"
echo "  ddev xhgui-query runs --format json"
echo "  ddev xhgui-query runs --sort wt"
echo "  ddev xhgui-query top-functions"
echo "  ddev xhgui-query top-functions --format json"
echo ""
echo "  Teardown: bash tests/teardown-local-test.sh"
