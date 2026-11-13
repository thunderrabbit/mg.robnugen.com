---
title: Rector's PHP {target} rules, one per commit
status: proven
whose_move: agent
check: rector_level
param: target
---
`rector.php` applies the level set for the site's target PHP
(`LevelSetList::UP_TO_PHP_85` for 8.5, `UP_TO_PHP_83` for 8.3), and a dry run
finds nothing left to change. The code then uses the language as the target
has it, and the calls the target deprecates are gone.

## How to do it

1. Do the tooling step first. On an older image Rector skips the target's rules
   and says there is nothing to do.
2. Add `rector/rector` to the dev requirements and a `rector.php` over the same
   paths as `phpcs.xml`, skipping `vendor` and generated test output. Take the
   Rector version composer resolves: the newest ones want a newer PHPStan than
   a site may have locked, and bumping PHPStan is its own step. Skip any code
   that also runs under an older PHP elsewhere (mg's journal sync runs on 8.1).
3. Dry run: `~/work/rob/standards-mcp/run-rector.sh <repo>`. Exit 2 means it
   found changes.
4. Apply one rule at a time with `--apply --only=<rule class>`, and commit each
   as `Rector <RuleName>: <what changed>`. Rector names rules by class name
   only; find the namespace with `grep -rl "class <RuleName>" vendor/rector/rector/rules`.
   Expect a second dry run to surface a few more rules once the first batch has
   landed (arrow functions, multi-catch, array_find), and apply those the same way.
5. Between rules run the Unit suite and phpcs. phpcbf tidies what Rector
   leaves untidy.
6. Read every diff. A rule that changes behaviour, not just spelling, gets a
   test first.
7. While the server still serves an older PHP, do not apply a rule that emits
   syntax only the target has (typed class constants need 8.3, for example),
   and lint what Rector rewrote with the PHP the server serves before
   deploying. A site that must not get ahead of its server leaves this step
   until it serves the target.
