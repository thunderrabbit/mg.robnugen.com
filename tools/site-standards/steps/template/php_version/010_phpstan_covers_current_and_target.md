---
title: PHPStan checks the current PHP and {target} in one run
status: idea
whose_move: agent
check: phpstan_php_range
param: target
---
Every PHPStan config the site runs (`phpstan.neon`, or each
`phpstan-level-*.neon` tier) sets a `phpVersion` range from the PHP the server
serves today to the site's target. One run then reports code that would break
on either: a call the target removed, and syntax the current PHP doesn't have
yet. A site already on its target passes with a plain `phpVersion` for that
PHP.

## How to do it

1. In each config, replace `phpVersion: 80100` (or add one) with the range,
   written as PHPStan writes versions:

       phpVersion:
           min: 80100
           max: 80300

2. Add `phpstan/phpstan-deprecation-rules` to the dev requirements and include
   its `rules.neon`, so calls the target deprecates are reported too.
3. Run PHPStan and count what is new. Post the count where the site tracks its
   upgrade before fixing anything: it sizes the work.
4. Fix the findings in their own commits, or baseline only what an existing
   baseline already holds for the same reason. Never raise the floor (`min`)
   while the server still serves that PHP.
5. After the server moves to the target, raise `min` to it.
