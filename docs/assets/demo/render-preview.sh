#!/usr/bin/env bash
# Render demo-preview.gif — a styling preview of the demo that needs no live
# DDEV project. Uses the mocks under preview/ to reproduce the sample output
# from README.md. For the real launch GIF, render demo.tape from inside a
# profiled DDEV project instead.
set -euo pipefail
cd "$(dirname "$0")"
exec vhs demo-preview.tape
