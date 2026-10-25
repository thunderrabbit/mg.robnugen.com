<?php

declare(strict_types=1);

namespace Inventory;

final readonly class EmptyDatabaseVerdict
{
    /**
     * @param list<int> $accepted database ids that may be marked
     * @param array<int, string> $refused why each other id may not, by database id
     */
    public function __construct(
        public array $accepted,
        public array $refused,
    ) {
    }
}
