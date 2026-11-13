<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * When a site scanned off the commit its server runs may be imported. Two
 * cases are allowed. A site preparing a long upgrade on its own line was
 * checked on that branch, as mg has it on record. A site with a test domain
 * and no upgrade branch was checked at the commit its test domain runs, so a
 * deploy there is enough to move it on the page. A site with an upgrade
 * branch never uses the second: its two lines really differ.
 */
final class OffServerScan
{
    /**
     * The sentence to refuse with, or null when the scan may be imported.
     * Asked only for a scanned site whose commit is not its server's.
     */
    public static function refusal(
        string $shortcode,
        ?string $branch,
        string $commit,
        ?string $testCommit,
        ?string $upgradeBranch,
        bool $hasTestDomain,
    ): ?string {
        $on = $branch === null ? 'a detached HEAD' : "branch {$branch}";
        $start = "site {$shortcode} was checked on {$on}, not at the commit its server runs, ";
        if ($upgradeBranch !== null) {
            return $branch === $upgradeBranch ? null : $start . "and its upgrade branch is {$upgradeBranch}";
        }
        if (!$hasTestDomain) {
            return $start . 'and has no upgrade branch or test domain on record';
        }
        if ($testCommit === null) {
            return $start . 'and its test domain was not asked what it runs';
        }
        if ($testCommit !== $commit) {
            return $start . 'and its test domain runs ' . substr($testCommit, 0, 7);
        }
        return null;
    }
}
