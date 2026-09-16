<?php

declare(strict_types=1);

namespace Db;

/**
 * Narrows what PDO hands back. fetch() answers false on an empty result set,
 * and both fetch() and fetchAll() are typed as mixed, so without this a row
 * reaches RowValue as mixed and every caller repeats the same is_array()
 * check.
 *
 * A row stays array<mixed> and is read column by column with RowValue: nothing
 * here promises the keys are strings, which is how PdoIssueStore's private
 * one()/all() already work.
 */
final class Rows
{
    /**
     * One row, or null when the query matched nothing.
     *
     * @return array<mixed>|null
     */
    public static function one(mixed $fetched): ?array
    {
        return is_array($fetched) ? $fetched : null;
    }

    /**
     * Every row that came back, reindexed so the analyser sees a list.
     *
     * @return list<array<mixed>>
     */
    public static function all(mixed $fetched): array
    {
        if (!is_array($fetched)) {
            return [];
        }

        $rows = [];
        foreach ($fetched as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
