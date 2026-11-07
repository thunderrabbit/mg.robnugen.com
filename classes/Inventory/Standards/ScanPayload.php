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
 *                 "skipped_reason": null,
 *                 "results": [{"step": "...", "outcome": "pass", "detail": "..."}]}]}
 *
 * Everything is checked here, before the importer opens a transaction.
 */
final readonly class ScanPayload
{
    private const int DETAIL_MAX = 255;

    /**
     * @param list<array{shortcode: string, branch: ?string, commit: ?string, skippedReason: ?string, results: list<array{step: string, outcome: Outcome, detail: string}>}> $sites
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
     * @return array{shortcode: string, branch: ?string, commit: ?string, skippedReason: ?string, results: list<array{step: string, outcome: Outcome, detail: string}>}
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
        $skipped = self::strOrNull($site, 'skipped_reason', $where);
        if ($commit !== null && !self::isSha($commit)) {
            throw new StandardsFormatException("{$where}: commit must be a full 40-character commit hash");
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
        if ($skipped === null && ($branch === null || $commit === null)) {
            throw new StandardsFormatException("{$where} was scanned, so it needs a branch and a commit");
        }

        return [
            'shortcode' => $shortcode,
            'branch' => $branch,
            'commit' => $commit,
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
