<?php

declare(strict_types=1);

namespace Inventory;

/**
 * What an import must do to one family of state rows: close these spans at the
 * new sweep, then open those. A changed thing appears in both lists.
 */
final readonly class SpanChanges
{
    /**
     * @param list<int> $closeSpanIds spans whose until_sweep_id becomes the new sweep
     * @param list<SweptSpan> $opens spans to insert with from_sweep_id = the new sweep
     * @param int $unchanged how many open spans already matched the sweep
     */
    public function __construct(
        public array $closeSpanIds,
        public array $opens,
        public int $unchanged,
    ) {
    }
}
