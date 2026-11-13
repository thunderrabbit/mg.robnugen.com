---
title: Checks in place (phpcs, PHPStan, unit tests)
status: proven
whose_move: agent
check: checks_in_place
---
The repo has a `phpcs.xml`, a `phpstan.neon`, a Unit suite under `Tests/Unit/`
and a tracked pre-commit hook that is switched on: `core.hooksPath` names a
directory in the repo (`.githooks/` by convention; `hooks/` on older sites),
and the hook is committed there. Every later step changes code, and
these are what say a change did not break anything. Without them a rewrite by
Rector is a guess.

## How to do it

1. Copy the dev tooling from a site that has it: `composer.json` dev
   requirements, `phpcs.xml`, `phpstan.neon`, `codeception.yml`,
   `Tests/Unit/_bootstrap.php` and `.githooks/pre-commit`.
2. Populate `vendor/` with the stock composer image (Lemur 13 has no composer).
3. Start PHPStan at the highest level that passes today, with no baseline.
   Climbing to the top is its own step.
4. Give phpcs the excludes it needs to pass today. Clearing them is its own
   step.
5. Write at least one real Unit test, so the suite is not empty. A site that
   already has tests somewhere else (lowercase `tests/`, or PHPUnit's own
   setup) does not start a second suite: the Codeception track's steps say
   how to bring those to `Tests/Unit/`, and doing that here meets this line.
6. Switch the hook on: `git config core.hooksPath .githooks` (or whichever
   tracked directory the site keeps its hooks in).
7. Add the repo to standards-mcp's `repos.yaml` so the runner scripts know its
   PHP target.
