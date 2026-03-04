#!/usr/bin/env bash
# Tears down the local DDEV test project.
# Usage: bash tests/teardown-local-test.sh

set -eu -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_DIR="$SCRIPT_DIR/../test-project"
PROJECT_NAME="xhgui-cli-test"

if [ ! -d "$TEST_DIR" ]; then
  echo "No test project found at $TEST_DIR"
  exit 0
fi

cd "$TEST_DIR"

echo "==> Stopping and removing DDEV project '$PROJECT_NAME'..."
ddev stop --remove-data --omit-snapshot 2>/dev/null || true

echo "==> Removing test project directory..."
rm -rf "$TEST_DIR"

echo "==> Teardown complete."
