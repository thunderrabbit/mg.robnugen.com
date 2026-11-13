<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The one JSON document the scanner on Lemur 13 sends: the step files as they
 * stand in the commit deployed on mg, and for each site either what its checks said or why the
 * site was skipped.
 *
 *     {"scanned_at_utc": "2026-11-07 01:20:00", "steps_commit": "<40 hex>",
 *      "files": {"template/_standard.md": "...", ...},
 *      "sites": [{"shortcode": "mg", "branch": "master", "commit": "<40 hex>",
 *                 "server_branch": "master", "server_commit": "<40 hex>",
 *                 "php_target": "8.5", "skipped_reason": null,
 *                 "results": [{"step": "...", "outcome": "pass", "detail": "..."}]}]}
 *
 * branch and commit are the checkout's; server_branch and server_commit are
 * what the site's server ran when asked, null when it could not be asked (and
 * server_branch null on a detached HEAD). Both server fields may be left out.
 * php_target is the PHP the site is moving to, as standards-mcp's repos.yaml
 * gave it (major.minor), null or left out when the registry gives none.
 * test_commit is the commit the site's test domain ran, set only when the
 * scanner asked it; null or left out otherwise.
 * A scanned site has both commits. They differ only for a site checked on the
 * branch it is preparing or at the commit its test domain runs; whether mg
 * has that branch or that test domain on record is the importer's question,
 * since it has the database.
 *
 * Everything is checked here, before the importer opens a transaction.
 */
final readonly class ScanPayload
{
    private const int DETAIL_MAX = 255;

    /**
     * @param list<array{shortcode: string, branch: ?string, commit: ?string, serverBranch: ?string, serverCommit: ?string, testCommit: ?string, phpTarget: ?string, skippedReason: ?string, results: list<array{step: string, outcome: Outcome, detail: string}>}> $sites
     */
    private function __construct(
        public string $scannedAtUtc,
        public string $stepsCommit,
        public StepTree $tree,
        public array $sites,
    ) {
    }

    /**
     * @param array<mixed> $data
     * @throws StandardsFormatException
     */
    public static function fromArray(array $data): self
    {
        $scannedAt = self::str($data, 'scanned_at_utc', 'the document');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $scannedAt, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $scannedAt) {
            throw new StandardsFormatException('scanned_at_utc must look like 2026-11-07 01:20:00');
        }

        $stepsCommit = self::str($data, 'steps_commit', 'the document');
        if (!self::isSha($stepsCommit)) {
            throw new StandardsFormatException('steps_commit must be a full 40-character commit hash');
        }

        $files = [];
        foreach (self::arr($data, 'files', 'the document') as $path => $content) {
            if (!is_string($path) || !is_string($content)) {
                throw new StandardsFormatException('files must map each path to its text');
            }
            $files[$path] = $content;
        }
        if ($files === []) {
            // An empty tree would read as "every step was deleted" and retire them all.
            throw new StandardsFormatException('the document carries no step files');
        }

        $sites = [];
        $seen = [];
        foreach (self::arr($data, 'sites', 'the document') as $site) {
            if (!is_array($site)) {
                throw new StandardsFormatException('each entry of sites must be an object');
            }
            $read = self::site($site);
            if (isset($seen[$read['shortcode']])) {
                throw new StandardsFormatException("site {$read['shortcode']} appears twice");
            }
            $seen[$read['shortcode']] = true;
            $sites[] = $read;
        }

        return new self($scannedAt, $stepsCommit, StepTree::fromFiles($files), $sites);
    }

    /**
     * @param array<mixed> $site
     * @return array{shortcode: string, branch: ?string, commit: ?string, serverBranch: ?string, serverCommit: ?string, testCommit: ?string, phpTarget: ?string, skippedReason: ?string, results: list<array{step: string, outcome: Outcome, detail: string}>}
     */
    private static function site(array $site): array
    {
        $shortcode = self::str($site, 'shortcode', 'a site');
        if (!Names::isShortcode($shortcode)) {
            throw new StandardsFormatException("\"{$shortcode}\" is not a valid shortcode");
        }
        $where = "site {$shortcode}";
        $branch = self::strOrNull($site, 'branch', $where);
        $commit = self::strOrNull($site, 'commit', $where);
        $serverBranch = array_key_exists('server_branch', $site) ? self::strOrNull($site, 'server_branch', $where) : null;
        $serverCommit = array_key_exists('server_commit', $site) ? self::strOrNull($site, 'server_commit', $where) : null;
        $testCommit = array_key_exists('test_commit', $site) ? self::strOrNull($site, 'test_commit', $where) : null;
        $phpTarget = array_key_exists('php_target', $site) ? self::strOrNull($site, 'php_target', $where) : null;
        $skipped = self::strOrNull($site, 'skipped_reason', $where);
        if ($commit !== null && !self::isSha($commit)) {
            throw new StandardsFormatException("{$where}: commit must be a full 40-character commit hash");
        }
        if ($serverCommit !== null && !self::isSha($serverCommit)) {
            throw new StandardsFormatException("{$where}: server_commit must be a full 40-character commit hash");
        }
        if ($testCommit !== null && !self::isSha($testCommit)) {
            throw new StandardsFormatException("{$where}: test_commit must be a full 40-character commit hash");
        }
        if ($serverBranch !== null && preg_match('#\A[A-Za-z0-9._/-]{1,100}\z#', $serverBranch) !== 1) {
            throw new StandardsFormatException("{$where}: server_branch is not a branch name");
        }
        if ($phpTarget !== null && preg_match('/\A\d+\.\d+\z/', $phpTarget) !== 1) {
            throw new StandardsFormatException("{$where}: php_target must look like 8.5");
        }
        if ($skipped !== null && ($skipped === '' || strlen($skipped) > self::DETAIL_MAX)) {
            throw new StandardsFormatException("{$where}: skipped_reason must be 1 to 255 characters");
        }

        $results = [];
        $steps = [];
        foreach (self::arr($site, 'results', $where) as $result) {
            if (!is_array($result)) {
                throw new StandardsFormatException("{$where}: each result must be an object");
            }
            $step = self::str($result, 'step', $where);
            if (!Names::isStepName($step)) {
                throw new StandardsFormatException("{$where}: \"{$step}\" is not a valid step name");
            }
            if (isset($steps[$step])) {
                throw new StandardsFormatException("{$where}: step {$step} is reported twice");
            }
            $steps[$step] = true;
            $outcome = Outcome::tryFrom(self::str($result, 'outcome', $where))
                ?? throw new StandardsFormatException("{$where}: outcome of {$step} must be pass, fail or unknown");
            $detail = self::str($result, 'detail', $where);
            if (strlen($detail) > self::DETAIL_MAX) {
                throw new StandardsFormatException("{$where}: detail of {$step} is over 255 characters");
            }
            $results[] = ['step' => $step, 'outcome' => $outcome, 'detail' => $detail];
        }

        if ($skipped !== null && $results !== []) {
            throw new StandardsFormatException("{$where} was skipped, so it cannot also carry results");
        }
        if ($skipped === null && ($commit === null || $serverCommit === null)) {
            throw new StandardsFormatException(
                "{$where} was scanned, so it needs its own commit and the commit its server runs"
            );
        }

        return [
            'shortcode' => $shortcode,
            'branch' => $branch,
            'commit' => $commit,
            'serverBranch' => $serverBranch,
            'serverCommit' => $serverCommit,
            'testCommit' => $testCommit,
            'phpTarget' => $phpTarget,
            'skippedReason' => $skipped,
            'results' => $results,
        ];
    }

    private static function isSha(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{40}\z/', $value) === 1;
    }

    /**
     * @param array<mixed> $record
     */
    private static function str(array $record, string $field, string $where): string
    {
        return self::strOrNull($record, $field, $where)
            ?? throw new StandardsFormatException("{$where}: {$field} must be text");
    }

    /**
     * @param array<mixed> $record
     */
    private static function strOrNull(array $record, string $field, string $where): ?string
    {
        if (!array_key_exists($field, $record)) {
            throw new StandardsFormatException("{$where}: {$field} is missing");
        }
        $value = $record[$field];
        if ($value !== null && !is_string($value)) {
            throw new StandardsFormatException("{$where}: {$field} must be text");
        }
        return $value;
    }

    /**
     * @param array<mixed> $record
     * @return array<mixed>
     */
    private static function arr(array $record, string $field, string $where): array
    {
        if (!array_key_exists($field, $record)) {
            throw new StandardsFormatException("{$where}: {$field} is missing");
        }
        $value = $record[$field];
        if (!is_array($value)) {
            throw new StandardsFormatException("{$where}: {$field} must be a list or an object");
        }
        return $value;
    }
}
