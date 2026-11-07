---
title: PHPStan level 10, no baseline
position: 10
status: proven
whose_move: agent
check: phpstan_top
---
`phpstan.neon` is at level 10, there is no baseline file, and a run is clean.
Level 10 is where PHPStan stops trusting `mixed`, which is where request data
and database rows come in. A baseline hides findings, so a clean run with one
says little.

## How to do it

1. Climb one level per bubble. Do not jump.
2. Fix each finding; never baseline it and never lower the level.
3. An entry script names the globals it takes from `prepend.php` in a `@var`
   block under the include, so PHPStan knows their types.
4. A finding PHPStan cannot see past gets
   `// @phpstan-ignore <identifier> (reason)` on that one line, never a
   blanket ignore.
5. Vendored third-party files are excluded from analysis, not fixed.
