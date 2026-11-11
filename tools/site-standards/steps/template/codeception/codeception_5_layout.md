---
title: Tests are in Tests/, laid out for Codeception 5
position: 20
status: proven
whose_move: agent
check: codeception_layout
---
Codeception 4 kept tests in lowercase `tests/`, with `tests/_support`,
`tests/_data` and suites named `unit` and `acceptance`. Codeception 5 keeps
them in a `Tests` namespace: `Tests/`, `Tests/Support`, `Tests/Support/Data`
and capitalized suites such as `Tests/Unit`. Rob prefers the new layout, and
a site that upgraded Codeception without moving its files still has the old
one.

It comes before the Tooling track because everything after it names the
path: the pre-commit hook, `phpcs.xml`, `phpstan.neon`, the Groundwork
step's `Tests/Unit/`, and the checks that leave `Tests/` out of their count.
Linux tells `tests/` from `Tests/`, so until the move those either miss the
site's tests or count them as page code.

The check wants nothing tracked under a top-level `tests/` in any other
spelling than `Tests/`. For a repo with a `codeception.yml` it also wants
that file to name the `Tests` namespace and the four paths below, nothing
tracked under `Tests/_support` or `Tests/_data`, and every suite file named
`Tests/<Capitalized>.suite.yml`. A repo with no tests passes.

## How to do it

1. If `composer.json` still asks for Codeception 4, move it to `^5.0` first
   (and each `codeception/module-*` to the version that goes with it), in its
   own commit, and get the suite passing where it is.
2. If the files under `tests/` are PHPUnit's own (a `phpunit.xml` names
   them), stop: that is the step before this one.
3. Move the files, and nothing else, in one commit, so git records renames
   and each file's history follows (`Move the tests to Tests/`):

       git mv tests Tests
       git mv Tests/_support Tests/Support
       git mv Tests/_data/* Tests/Support/Data/     # then remove Tests/_data
       git mv Tests/unit Tests/Unit
       git mv Tests/unit.suite.yml Tests/Unit.suite.yml

   with the last two repeated for every suite. Where `Tests/Support/Data`
   already exists, move the files into it one by one.
4. Make `codeception.yml` say:

       namespace: Tests
       support_namespace: Support
       paths:
           tests: Tests
           output: Tests/_output
           data: Tests/Support/Data
           support: Tests/Support
           envs: Tests/_envs

   keeping the site's own `actor_suffix`, `extensions` and anything else.
5. Give the classes their namespaces. An actor (`UnitTester`) is `namespace
   Tests\Support;`, a helper is `namespace Tests\Support\Helper;`, and a test
   in `Tests/Unit/` is `namespace Tests\Unit;`. Each suite file gets
   `suite_namespace: Tests\Unit` (its own suite's name) and names its helpers
   in full, `\Tests\Support\Helper\Unit`. A test that takes an actor adds
   `use Tests\Support\UnitTester;`. A class from the global namespace now
   needs its leading backslash (`\PDO`, `\Config`).
6. The generated actor traits are not tracked. `.gitignore` gets

       Tests/_output/*
       !Tests/_output/.gitkeep
       Tests/_envs/
       Tests/Support/_generated/*
       !Tests/Support/_generated/.gitkeep

   and any tracked file under `_generated/` leaves the index with `git rm
   --cached`. Rebuild them with `vendor/bin/codecept build`, in the site's
   runner image.
7. Find every other mention of the old paths, `git grep -nE
   'tests/|_support|_data' -- ':!Tests' ':!vendor'`: `phpcs.xml`,
   `phpstan.neon`, the hooks, `safe-suite.sh`, run scripts, CLAUDE.md and any
   tool that reads a file under the old `_support`. Commit steps 4 to 7
   together (`Lay the tests out for Codeception 5`).
8. Run `~/work/rob/standards-mcp/run-unit.sh <repo>` and the site's other
   gates, then this step's check. A suite that drives a browser or a live
   site cannot be run from here; tell Rob which ones were renamed but not
   run.
