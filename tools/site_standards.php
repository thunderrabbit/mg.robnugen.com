<?php

/**
 * Keeps the hand-entered facts about a site for the standards page: its
 * shortcode and standard, its mg project, where its code is, and which
 * DreamHost domains serve it. Run it on the server, or from Lemur 13 through
 * tools/site-standards/site.sh.
 *
 *     php tools/site_standards.php sites
 *     php tools/site_standards.php site-add --shortcode=example --standard=template --project=7
 *     php tools/site_standards.php site-set --shortcode=example --checkout=rob/example.test
 *     php tools/site_standards.php site-link --shortcode=example --domain=example.test
 *
 * See Inventory\Standards\SiteCommand for every command. A refusal is one
 * sentence on stderr and exit 1.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../classes/Mlaphp/Autoloader.php';
spl_autoload_register([new \Mlaphp\Autoloader(), 'load']);

$given = $_SERVER['argv'] ?? [];
$args = [];
foreach (array_slice(is_array($given) ? $given : [], 1) as $arg) {
    if (is_string($arg)) {
        $args[] = $arg;
    }
}

try {
    // Read the arguments before connecting, so a typo needs no database.
    \Inventory\Standards\SiteCommand::parse($args);
    $store = new \Inventory\Standards\SiteStore(\Database\Base::getPDO(new \Config()));
    echo \Inventory\Standards\SiteCommand::run($args, $store), "\n";
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
