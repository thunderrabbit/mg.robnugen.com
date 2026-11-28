---
title: PHPStan level 4, no baseline
status: proven
whose_move: agent
check: phpstan_level
param: 4
---
All of the site's code is held to PHPStan level 4 or above, no baseline hides
a finding, and a run is clean.

A site that keeps one config per level (`phpstan-level-N.neon`, each over its
own directories) is at the lowest of them: the level all of its code is held
to. There, a baseline in any tier counts against every level, and raising the
level means moving the last directories out of the lowest tier. A baseline
file with no entries hides nothing and is not a baseline.

Level 4 reports basic dead code: an `instanceof` or other type check that is
always false, an `else` that can never run, code after a `return`. Dead code
is often a sign that a condition does not test what its author meant, so
read each one before deleting it.

## How to do it

1. Do level 3 first. Climb one level per bubble; do not jump.
2. Open the bubble with `BEGIN PHPStan level 4`: the site's usual tiny version
   uptick and nothing else.
3. List the findings without changing the config: run PHPStan with
   `--level 4` in the site's runner image.
   Leave `level:` at 3 until the last commit, so every commit on
   the way still passes the hook.
4. Sort the findings by rule: the identifier PHPStan gives each one, or one
   cause when a single kind of fix answers several identifiers. At this level
   the rules tend to be:
   checks that are always false, unreachable code.
5. A rule whose fixes span several files, each worth its own commit, gets a
   nested bubble inside this one:
   - `BEGIN Level 4: <what the rule's fixes do>`, again only a version
     uptick. Its message says which inner bubble this is and what the rule
     finds.
   - One commit per file or area: `Level 4: <what changed there>`.
   - `DONE Level 4: <the same words>` closes it, before the next rule begins.
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
12. When a run at `--level 4` is clean, the last commits of the outer bubble
    are `PHPStan: raise the level to 4` (only the PHPStan config) and one for
    any of the site's notes that state the level. Then close the outer bubble.
