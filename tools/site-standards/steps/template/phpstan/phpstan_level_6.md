---
title: PHPStan level 6, no baseline
position: 60
status: proven
whose_move: agent
check: phpstan_level
param: 6
---
`phpstan.neon` is at level 6 or above, there is no baseline file, and a run is
clean.

Level 6 reports missing type hints: a parameter, a return or a property with
no declared type, and an array that does not say what it holds. It is the
longest rung, because it asks every function on the site to say what it
takes and gives. The levels above can only check what this one writes down.

## How to do it

1. Do level 5 first. Climb one level per bubble; do not jump.
2. Open the bubble with `BEGIN PHPStan level 6`: the site's usual tiny version
   uptick and nothing else.
3. List the findings without changing the config: run PHPStan with
   `--level 6` in the site's runner image.
   Leave `level:` at 5 until the last commit, so every commit on
   the way still passes the hook.
4. Sort the findings by rule: the identifier PHPStan gives each one, or one
   cause when a single kind of fix answers several identifiers. At this level
   the rules tend to be:
   parameter types, return types, property types, what each array holds.
5. A rule whose fixes span several files, each worth its own commit, gets a
   nested bubble inside this one:
   - `BEGIN Level 6: <what the rule's fixes do>`, again only a version
     uptick. Its message says which inner bubble this is and what the rule
     finds.
   - One commit per file or area: `Level 6: <what changed there>`.
   - `DONE Level 6: <the same words>` closes it, before the next rule begins.
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
12. When a run at `--level 6` is clean, the last commits of the outer bubble
    are `PHPStan: raise the level to 6` (only `phpstan.neon`) and one for any
    of the site's notes that state the level. Then close the outer bubble.
