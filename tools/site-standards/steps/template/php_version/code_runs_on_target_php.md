---
title: Code compiles on the current PHP and on {target}
position: 2
status: proven
whose_move: agent
check: code_runs_on_php
param: target
---
Every PHP file in the site compiles on the site's target PHP and on the PHP
the site is served on today. The code is deployed while the server still
serves the old PHP and only then switched, so it has to survive both.
Anything the target removed or deprecated is fixed here; syntax that only the
target has comes later, in the Rector step.

This is the compile check for every site, whether or not it has a test domain.
It proves the files parse, not that they behave: behaviour is the test-suite step.

## How to do it

1. Do the tooling step first, so the checks run on the target's image.
2. Run Rector on that image with only the rules that fix removals and
   deprecations, never the sets that introduce newer syntax: `run-rector.sh
   <repo> --only=<rule class>`, one rule per commit, reading every diff.
3. Fix what the PHPStan step's version range now reports.
4. Lint on both versions: `run-php-lint.sh <repo> <target>`, and again with the
   PHP the site is served on. A file that fails on the old version has used
   newer syntax by mistake; rewrite it.
5. Going past 8.1 means 8.2's deprecations too. The dynamic-properties step
   covers the big one.
6. Deploy. The next scan ticks this step.
