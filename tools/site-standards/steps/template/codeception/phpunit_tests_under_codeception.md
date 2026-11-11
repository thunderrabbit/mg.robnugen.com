---
title: PHPUnit tests run under Codeception
position: 10
status: idea
whose_move: agent
check: phpunit_under_codeception
---
A site whose tests were written for PHPUnit alone (a `phpunit.xml`, a
`tests/bootstrap.php`, `phpunit/phpunit` in `composer.json`) runs them under
Codeception 5 instead, like every other site. The gates in standards-mcp
(`run-unit.sh`, the pre-commit hook, `check_tests`) only know how to run a
Codeception Unit suite, so a PHPUnit-only site has tests that nothing here
runs.

Codeception runs a class that extends `PHPUnit\Framework\TestCase` as it is,
so this is a change of runner and layout, not a rewrite of the tests.

The check wants no `phpunit*.xml` tracked at the repo root, and no
`phpunit/phpunit` in `composer.json` without `codeception/codeception`. A
site that never had a PHPUnit setup passes.

## How to do it

No site has done this yet, so treat these as a sketch and tell Rob what did
not fit.

1. Add the dev requirements and `codeception.yml` from a site that has them
   (`codeception/codeception` ^5.0 and `codeception/module-asserts`), and
   drop the direct `phpunit/phpunit` requirement: Codeception brings its own.
2. Sort the tests by what they need. A test that needs nothing but the code
   goes to `Tests/Unit/`. A test that needs a database, credentials or the
   network goes to `Tests/Integration/`: the Unit gate runs with no network
   and no credentials, and a test that needs them aborts it.
3. Turn what `phpunit.xml` set up into Codeception's: its `bootstrap` becomes
   `Tests/Unit/_bootstrap.php` (named in `Tests/Unit.suite.yml`), and its
   `<env>` values go to the suite that needs them, never to the Unit suite.
4. Delete `phpunit*.xml` and any script that only called `vendor/bin/phpunit`,
   or point that script at `vendor/bin/codecept run`.
5. Run `~/work/rob/standards-mcp/run-unit.sh <repo>`, then this step's check.
   The next step puts the files where Codeception 5 wants them.
