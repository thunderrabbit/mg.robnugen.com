---
title: Tooling runs on PHP {target}
position: 10
status: proven
whose_move: agent
check: tooling_on_php
param: target
---
The site's checks run on the PHP the site is moving to, its `php_target` in
standards-mcp's `repos.yaml` (8.5 for most sites; a site that can't jump yet
has a nearer one). That means the pre-commit hook names that PHP's runner
image, `composer.json`'s PHP requirement allows that PHP, and the registry
gives the repo that target.

This comes before any Rector step. Rector takes its target version from the
PHP it runs on, so on an older image it silently skips the newer rules and
reports nothing to do.

## How to do it

1. In standards-mcp's `repos.yaml`, give the repo its `php_target`. Rob
   chooses it: the PHP the site's server can next serve.
2. In the tracked pre-commit hook (`.githooks/pre-commit`, or wherever
   `core.hooksPath` points), set the image to
   `standards-codeception-runner:php<target>`.
3. In `composer.json`, make `require.php` allow the target. While the server
   still serves an older PHP, keep the floor at that PHP (`>=8.1`, say) rather
   than at the target, or Composer refuses to install on the server.
4. If the site carries a `phpVersion` in its PHPStan config, the PHPStan step
   says what it should be.
5. Run the Unit suite, phpcs and PHPStan on the new image and fix what they
   now report.
6. While the server still serves an older PHP, lint anything you change with
   that PHP too before deploying.
