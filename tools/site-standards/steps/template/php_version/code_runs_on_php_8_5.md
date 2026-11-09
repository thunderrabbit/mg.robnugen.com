---
title: Code compiles on the current PHP and on 8.5
position: 2
status: proven
whose_move: agent
check: code_runs_on_php
param: 8.5
---
Every PHP file in the site compiles on PHP 8.5 and on the PHP the site is served
on today. The code is deployed while the server still serves the old PHP and only
then switched, so it has to survive both. Anything 8.5 removed or deprecated is
fixed here; the 8.5-only syntax comes later, in the Rector step.

This is the compile check for every site, whether or not it has a test domain.
It proves the files parse, not that they behave: behaviour is the test-suite step.

## How to do it

1. Do the tooling step first, so the checks run on the 8.5 image.
2. Run Rector on the 8.5 image with only the rules that fix removals and
   deprecations, never the sets that introduce newer syntax: `run-rector.sh
   <repo> --only=<rule class>`, one rule per commit, reading every diff.
3. Set `phpVersion` in `phpstan.neon` to the target and fix what PHPStan now
   reports.
4. Lint on both versions: `run-php-lint.sh <repo> 8.5`, and again with the PHP the
   site is served on. A file that fails on the old version has used newer syntax
   by mistake; rewrite it.
5. Deploy. The next scan ticks this step.
