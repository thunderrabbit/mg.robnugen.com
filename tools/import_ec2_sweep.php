<?php

/**
 * Imports one EC2 sweep read from STDIN as a single JSON document, the one
 * tools/ec2-sweep/deets.sh prints on a server and tools/ec2-sweep/sweep.sh
 * streams here from Lemur 13:
 *
 *     {"ssh_alias": "example-ec2", "swept_at_utc": "2026-11-08 01:02:03",
 *      "web_server": "Apache/2.4.52 (Ubuntu)", "cli_php": "8.1.2",
 *      "domains": [{"domain": "example.test", "directory": "/var/www/example.test/current",
 *                   "php_version": "8.1.2", "probe_route": "http_forwarded", "probe_problem": "",
 *                   "directory_exists": true, "is_git_checkout": true,
 *                   "head_commit": "<40 hex>", "head_branch": "master",
 *                   "deployed_at_utc": "2026-11-01 10:00:00"}]}
 *
 * --dry-run does everything inside the transaction and rolls it back, so the
 * summary shows what an import would do. Exits 1 with the reason on stderr
 * when the sweep is refused.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../classes/Mlaphp/Autoloader.php';
spl_autoload_register([new \Mlaphp\Autoloader(), 'load']);

$options = getopt('', ['dry-run']);
$dry_run = is_array($options) && array_key_exists('dry-run', $options);

try {
    $json = stream_get_contents(STDIN);
    $data = json_decode($json === false ? '' : $json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new \Inventory\SweepFormatException('STDIN should hold one JSON object');
    }
    $sweep = \Inventory\Ec2\SweepPayload::fromArray($data);
    $summary = new \Inventory\Ec2\SweepImporter(\Database\Base::getPDO(new \Config()))->import($sweep, $dry_run);
} catch (\JsonException | \Inventory\SweepFormatException | \Inventory\SweepRefusedException $e) {
    fwrite(STDERR, 'REFUSED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo ($dry_run ? 'DRY RUN (rolled back)' : 'IMPORTED') . " sweep of {$sweep->sshAlias} at {$sweep->sweptAtUtc} UTC\n";
foreach ($summary as $family => $counts) {
    printf(
        "  %-16s opened %-4d closed %-4d unchanged %d\n",
        $family,
        $counts['opened'],
        $counts['closed'],
        $counts['unchanged'],
    );
}
