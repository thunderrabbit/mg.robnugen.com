<?php

/**
 * Rector config — keep mg's PHP at the version it serves, 8.5 (the standards
 * page's "Rector's PHP 8.5 rules" step). Same scope as phpcs.xml: everything
 * except the vendored Parsedown, the gitignored Config.php and Codeception's
 * generated output.
 *
 * Runs in Docker only, through standards-mcp:
 *   ~/work/rob/standards-mcp/run-rector.sh ~/work/rob/mg.robnugen.com            dry run (exit 2 = changes found)
 *   ~/work/rob/standards-mcp/run-rector.sh ~/work/rob/mg.robnugen.com --apply --only=<rule class>
 *
 * Rector takes its target version from the PHP it runs on, so it must run on
 * the php8.5 image (repos.yaml php_target), or it silently skips 8.5 rules.
 * Apply one rule per commit and run the Unit suite, phpcs and PHPStan between.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/classes',
        __DIR__ . '/wwwroot',
        __DIR__ . '/prepend.php',
        __DIR__ . '/Tests',
        __DIR__ . '/tools',
    ]);

    $rectorConfig->skip([
        __DIR__ . '/vendor',
        __DIR__ . '/classes/Parsedown.php',
        __DIR__ . '/classes/Config.php',
        __DIR__ . '/Tests/Support/_generated',
        __DIR__ . '/Tests/_output',
    ]);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_85,
    ]);
};
