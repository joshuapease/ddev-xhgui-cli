# demo.tape — canonical launch demo for ddev-xhgui-cli.
#
# Renders the README "agent loop, end to end" against a LIVE DDEV project.
# Run it from INSIDE a DDEV project that already has XHGui profiling data:
#
#   cd /path/to/your/ddev/project
#   vhs /path/to/ddev-xhgui-cli/docs/assets/demo/demo.tape   # writes ./demo.gif
#
# The grep target on the last step is illustrative — edit it to point at a real
# hot path in whatever project you record.
#
# For a styling preview that needs no DDEV project, run ./render-preview.sh.
#
# NOTE: the command sequence below is intentionally duplicated in
# demo-preview.tape. Keep the two in sync when you edit the flow.

Output demo.gif

Require ddev

Set Shell "bash"
Set FontSize 15
Set Width 1280
Set Height 720
Set Padding 24
Set Margin 16
Set MarginFill "#16161e"
Set BorderRadius 10
Set WindowBar Colorful
Set Theme "Dracula"
Set TypingSpeed 28ms

Hide
Type "export PS1='\[\e[36m\]➜  myproject\[\e[0m\] $ '" Enter
Type "clear" Enter
Show

Type "# turn profiling on, then hit the slow page" Sleep 300ms Enter
Type "ddev xhgui on" Sleep 300ms Enter
Sleep 900ms
Type "curl -s https://myproject.ddev.site/shop/products >/dev/null" Sleep 300ms Enter
Sleep 700ms

Type "# which requests were slow? sort by wall time" Sleep 300ms Enter
Type "ddev xhgui-query runs --sort wt --limit 5" Sleep 300ms Enter
Sleep 2.2s

Type "# break the slow run down by function" Sleep 300ms Enter
Type "ddev xhgui-query top-functions" Sleep 300ms Enter
Sleep 2.6s

Type "# PDO::query is internal — who calls it?" Sleep 300ms Enter
Type "ddev xhgui-query callers --function PDO::query" Sleep 300ms Enter
Sleep 2.6s

Type "# 82% comes through ProductRepository::all — go to the code" Sleep 300ms Enter
Type "grep -rn 'function all' src/Repository/ProductRepository.php" Sleep 300ms Enter
Sleep 2.2s

Sleep 1.2s
