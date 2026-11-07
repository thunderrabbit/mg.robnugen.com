<?php

/**
 * Imports one site-standards scan read from STDIN as a single JSON document.
 * Run it through tools/site-standards/scan.sh on Lemur 13, which reads the
 * step files from the commit deployed on mg, runs each site's checks and
 * streams the result:
 *
 *     {"scanned_at_utc": "2026-11-07 01:20:00", "steps_commit": "<40 hex>",
 *      "files": {"template/_standard.md": "...", ...},
 *      "sites": [{"shortcode": "mg", "branch": "master", "commit": "<40 hex>",
 *                 "skipped_reason": null,
 *                 "results": [{"step": "...", "outcome": "pass", "detail": "..."}]}]}
 *
 * --dry-run does everything inside the transaction and rolls it back, so the
 * summary shows what an import would do. Exits 1 with the reason on stderr
 * when the scan is refused.
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
        throw new \Inventory\Standards\StandardsFormatException('STDIN should hold one JSON object');
    }
    $scan = \Inventory\Standards\ScanPayload::fromArray($data);
    $importer = new \Inventory\Standards\StandardsImporter(\Database\Base::getPDO(new \Config()));
    $summary = $importer->import($scan, $dry_run);
} catch (
    \JsonException
    | \Inventory\Standards\StandardsFormatException
    | \Inventory\Standards\ScanRefusedException $e
) {
    fwrite(STDERR, 'REFUSED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo ($dry_run ? 'DRY RUN (rolled back)' : 'IMPORTED') . " scan of {$scan->scannedAtUtc} UTC\n";
foreach ($summary as $what => $count) {
    printf("  %-18s %d\n", $what, $count);
}
