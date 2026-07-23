# Demo assets

Source for the terminal demo GIF used in the project README and launch
materials. Rendered with [VHS](https://github.com/charmbracelet/vhs)
(`brew install vhs`), so the demo is a scripted, regenerable artifact rather
than a one-off screen recording.

## Files

| File                | What it is                                                                 |
| ------------------- | -------------------------------------------------------------------------- |
| `demo.tape`         | The canonical demo. Runs the real `ddev xhgui-query` commands.             |
| `demo-preview.tape` | A styling preview that needs no DDEV project (uses the mocks in `preview/`).|
| `render-preview.sh` | Renders `demo-preview.gif` from this directory.                            |
| `preview/`          | Mock `ddev`/`curl` and a fixture source file for the preview.              |
| `demo-preview.gif`  | Committed preview render — a reference for fonts/colors/pacing.            |

The command sequence is intentionally duplicated between `demo.tape` and
`demo-preview.tape`. If you change the flow, edit both.

## Rendering the preview (no DDEV needed)

```bash
./render-preview.sh          # writes demo-preview.gif
```

The mocks under `preview/bin/` emit the exact sample output documented in the
project `README.md`, and the tape `cd`s into `preview/` so the final `grep`
step finds `preview/src/Repository/ProductRepository.php`. This render is only
for checking the visual design — the numbers are canned.

## Rendering the real GIF (needs a live DDEV project)

`demo.tape` runs the real commands, so it needs a DDEV project that already has
XHGui profiling data:

```bash
cd /path/to/your/ddev/project
vhs /path/to/ddev-xhgui-cli/docs/assets/demo/demo.tape   # writes ./demo.gif
```

Before recording, edit the last `grep` step in `demo.tape` to point at a real
hot path in that project. The result becomes the `demo.gif` referenced from the
project README.
