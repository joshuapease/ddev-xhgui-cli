# demo-preview.tape — styling preview of demo.tape that needs NO DDEV project.
#
# Prepends preview/bin to PATH so `ddev` and `curl` resolve to the mocks in
# this directory, which emit the exact sample output documented in README.md,
# and cds into preview/ so the grep step finds the fixture source file. Run it
# via ./render-preview.sh (which invokes vhs from this directory).
#
# For checking fonts/colors/pacing only. The real launch GIF comes from
# demo.tape against a live project.
#
# NOTE: the command sequence below is intentionally duplicated in demo.tape.
# Keep the two in sync when you edit the flow.

Output demo-preview.gif

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
Type 'export PATH="$PWD/preview/bin:$PATH"' Enter
Type "cd preview" Enter
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
