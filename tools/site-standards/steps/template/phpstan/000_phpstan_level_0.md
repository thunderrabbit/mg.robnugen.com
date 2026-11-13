---
title: PHPStan level 0, no baseline
position: 0.1
status: proven
whose_move: agent
check: phpstan_level
param: 0
---
`phpstan.neon` is at level 0 or above, there is no baseline file, and a run is
clean.

Level 0 is where PHPStan starts, and the rung where the site gets PHPStan at
all. It checks the basics: unknown classes and functions, unknown methods
called on `$this`, the wrong number of arguments, variables that are never
defined. Most of what it finds on a site new to PHPStan is a real bug, a
missing include, or code nothing has run in years.

## How to do it

1. This is the bottom rung. Open the bubble with `BEGIN PHPStan level 0`: the
   site's usual tiny version uptick and nothing else.
2. Add `phpstan/phpstan` to `require-dev` in a commit of its own.
3. Write `phpstan.neon` with `level: 0`, the site's PHP version and the paths
   the site's own code lives in, and run PHPStan in the site's runner image.
   Do not commit the file yet: until it is committed nothing checks commits
   against it, so every commit on the way still passes.
4. Sort the findings by rule: the identifier PHPStan gives each one, or one
   cause when a single kind of fix answers several identifiers. At this level
   the rules tend to be:
   calls to functions and classes that no longer exist, wrong argument
   counts, globals an entry script takes from its include.
5. A rule whose fixes span several files, each worth its own commit, gets a
   nested bubble inside this one:
   - `BEGIN Level 0: <what the rule's fixes do>`, again only a version
     uptick. Its message says which inner bubble this is and what the rule
     finds.
   - One commit per file or area: `Level 0: <what changed there>`.
   - `DONE Level 0: <the same words>` closes it, before the next rule begins.
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
12. When a run is clean, the last commits of the outer bubble are
    `PHPStan: start at level 0` (only `phpstan.neon`) and one for any of the
    site's notes that say how to run it. Then close the outer bubble.
