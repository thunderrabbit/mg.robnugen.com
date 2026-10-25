<?php

declare(strict_types=1);

namespace Inventory;

/**
 * Rob's rule for the first triage slice (mg #775): a database may be marked
 * for deletion only when the latest sweep recorded exactly zero bytes for it.
 * "0 MB" or any size above zero is never taken to mean empty. No database in
 * here; the caller hands over the sizes it found.
 */
final class EmptyDatabaseRule
{
    /**
     * @param list<int> $requested database ids the form posted
     * @param array<int, int|null> $latestSizes size in bytes in the latest sweep, by database id;
     *                                          a missing id or null means not swept as present
     */
    public static function check(array $requested, array $latestSizes): EmptyDatabaseVerdict
    {
        $accepted = [];
        $refused = [];
        foreach (array_values(array_unique($requested)) as $databaseId) {
            $size = $latestSizes[$databaseId] ?? null;
            if ($size === null) {
                $refused[$databaseId] = 'not in the latest sweep';
            } elseif ($size !== 0) {
                $refused[$databaseId] = 'not empty: ' . $size . ' bytes';
            } else {
                $accepted[] = $databaseId;
            }
        }
        return new EmptyDatabaseVerdict($accepted, $refused);
    }
}
