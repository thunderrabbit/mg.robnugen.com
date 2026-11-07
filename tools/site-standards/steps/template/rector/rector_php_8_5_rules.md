---
title: Rector's PHP 8.5 rules, one per commit
position: 10
status: proven
whose_move: agent
check: rector_level
param: 8.5
---
`rector.php` applies `LevelSetList::UP_TO_PHP_85`, and a dry run finds nothing
left to change. The code then uses the language as PHP 8.5 has it, and the
calls 8.5 deprecates are gone before the server switches.

## How to do it

1. Do the tooling step first. On an older image Rector skips the 8.5 rules and
   says there is nothing to do.
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
   8.5-only syntax, and lint what Rector rewrote with the server's own `php -l`
   before deploying.
