<?php

declare(strict_types=1);

namespace Inventory;

/**
 * Where one family of state rows lives. The table, its primary key, the
 * columns that say which thing a span is about ($keyColumns) and the ones that
 * may change ($attrColumns); every span also has from_sweep_id and
 * until_sweep_id.
 *
 * $scopeSql limits the open spans to one account. It is a condition on the
 * alias `s` with one `?` for dh_account_id, and is written here, never taken
 * from a sweep.
 */
final readonly class SpanTable
{
    /**
     * @param list<string> $keyColumns
     * @param list<string> $attrColumns
     */
    public function __construct(
        public string $table,
        public string $idColumn,
        public array $keyColumns,
        public array $attrColumns,
        public string $scopeSql,
    ) {
    }
}
