---
title: Tooling runs on PHP 8.5
position: 10
status: proven
whose_move: agent
check: tooling_on_php
param: 8.5
---
The site's checks run on the same PHP the site is moving to: the pre-commit
hook names the PHP 8.5 runner image, `composer.json` asks for PHP 8.5, and
standards-mcp's `repos.yaml` gives the repo `php_target: "8.5"`.

This comes before any Rector step. Rector takes its target version from the
PHP it runs on, so on an older image it silently skips the newer rules and
reports nothing to do.

## How to do it

1. In `.githooks/pre-commit`, set the image to
   `standards-codeception-runner:php8.5`.
2. In `composer.json`, require `"php": ">=8.5"`.
3. In standards-mcp's `repos.yaml`, set the repo's `php_target` to `"8.5"`.
4. If the site carries a `phpVersion` in `phpstan.neon`, set it to `80500`.
5. Run the Unit suite, phpcs and PHPStan on the new image and fix what they
   now report.
6. While the server still serves an older PHP, lint anything you change with
   the server's own `php -l` for the version it serves before deploying.
