---
title: PHPStan level 1, no baseline
status: proven
whose_move: agent
check: phpstan_level
param: 1
---
`phpstan.neon` is at level 1 or above, there is no baseline file, and a run is
clean.

Level 1 adds variables that might be undefined, and unknown magic methods
and properties on classes with `__call` and `__get`. A variable set in only
one branch of an `if` and read after it is the usual finding.

## How to do it

1. Do level 0 first. Climb one level per bubble; do not jump.
2. Open the bubble with `BEGIN PHPStan level 1`: the site's usual tiny version
   uptick and nothing else.
3. List the findings without changing the config: run PHPStan with
   `--level 1` in the site's runner image.
   Leave `level:` at 0 until the last commit, so every commit on
   the way still passes the hook.
4. Sort the findings by rule: the identifier PHPStan gives each one, or one
   cause when a single kind of fix answers several identifiers. At this level
   the rules tend to be:
   variables set in only one branch, magic properties nothing declares.
5. A rule whose fixes span several files, each worth its own commit, gets a
   nested bubble inside this one:
   - `BEGIN Level 1: <what the rule's fixes do>`, again only a version
     uptick. Its message says which inner bubble this is and what the rule
     finds.
   - One commit per file or area: `Level 1: <what changed there>`.
   - `DONE Level 1: <the same words>` closes it, before the next rule begins.
6. A rule that touches only one or two files gets one commit per file, straight
   in the outer bubble, with no nested bubble around them.
7. Fix each finding; never baseline it and never lower the level.
8. An entry script names the globals it takes from `prepend.php` in a `@var`
   block under the include, so PHPStan knows their types.
9. A finding PHPStan cannot see past gets
   `// @phpstan-ignore <identifier> (reason)` on that one line, never a
   blanket ignore.
10. Vendored third-party files are excluded from analysis, not fixed.
11. Run the Unit suite and phpcs after each commit.
12. When a run at `--level 1` is clean, the last commits of the outer bubble
    are `PHPStan: raise the level to 1` (only `phpstan.neon`) and one for any
    of the site's notes that state the level. Then close the outer bubble.
