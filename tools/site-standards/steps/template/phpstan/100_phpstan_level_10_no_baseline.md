---
title: PHPStan level 10, no baseline
position: 100
status: proven
whose_move: agent
check: phpstan_level
param: 10
---
`phpstan.neon` is at level 10, there is no baseline file, and a run is clean.

Level 10 is the top. It treats a value with no declared type at all the same
way level 9 treats a declared `mixed`, which is where database rows and
undocumented arrays come in. A baseline hides findings, so a clean run with
one says little.

## How to do it

1. Do level 9 first. Climb one level per bubble; do not jump.
2. Open the bubble with `BEGIN PHPStan level 10`: the site's usual tiny version
   uptick and nothing else.
3. List the findings without changing the config: run PHPStan with
   `--level 10` in the site's runner image.
   Leave `level:` at 9 until the last commit, so every commit on
   the way still passes the hook.
4. Sort the findings by rule: the identifier PHPStan gives each one, or one
   cause when a single kind of fix answers several identifiers. At this level
   the rules tend to be:
   fetched rows get a shape, unknown data stays `mixed`.
5. A rule whose fixes span several files, each worth its own commit, gets a
   nested bubble inside this one:
   - `BEGIN Level 10: <what the rule's fixes do>`, again only a version
     uptick. Its message says which inner bubble this is and what the rule
     finds.
   - One commit per file or area: `Level 10: <what changed there>`.
   - `DONE Level 10: <the same words>` closes it, before the next rule begins.
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
12. When a run at `--level 10` is clean, the last commits of the outer bubble
    are `PHPStan: raise the level to 10` (only `phpstan.neon`) and one for any
    of the site's notes that state the level. Then close the outer bubble.
