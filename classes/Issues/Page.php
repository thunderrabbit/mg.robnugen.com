<?php

declare(strict_types=1);

namespace Issues;

/**
 * One page of a list read: the rows themselves, how many rows the filters match
 * in total, and the window they came from. Each surface wraps this in whatever
 * envelope it has always used.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {
    }
}
