<?php

/**
 * Imports one DreamHost sweep (mg #775) read from STDIN as a single JSON
 * document, so the sweep never lands on this machine's disk. Run it through
 * tools/import-dh-sweep.sh on Rob's laptop, which bundles the six files of
 * sweeps/YYYY-MM-DD/ from the dreamhost-inventory repo:
 *
 *     {"swept_on": "2026-10-24", "source_dir": "sweeps/2026-10-24",
 *      "files": {"domains": {...}, "users": {...}, "registrations": {...},
 *                "databases": {...}, "crons": {...}, "buckets": {...}}}
 *
 * --dry-run does everything inside the transaction and rolls it back, so the
 * summary shows what an import would do. Prints one line per family; exits 1
 * with the reason on stderr when the sweep is refused.
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
    $sweep = \Inventory\SweepPayload::fromArray($data);
    $summary = (new \Inventory\SweepImporter(\Database\Base::getPDO(new \Config())))->import($sweep, $dry_run);
} catch (\JsonException | \Inventory\SweepFormatException | \Inventory\SweepRefusedException $e) {
    fwrite(STDERR, 'REFUSED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo ($dry_run ? 'DRY RUN (rolled back)' : 'IMPORTED') . " sweep {$sweep->sweptOn} for {$sweep->accountName}\n";
foreach ($summary as $family => $counts) {
    printf(
        "  %-26s new %-4d opened %-4d closed %-4d unchanged %d\n",
        $family,
        $counts['new'],
        $counts['opened'],
        $counts['closed'],
        $counts['unchanged'],
    );
}
