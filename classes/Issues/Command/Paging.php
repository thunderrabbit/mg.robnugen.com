<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Input;

/**
 * The window a list read returns. Each list has its own default and ceiling, so
 * a caller asking for more than the ceiling gets the ceiling rather than an
 * error, which is how the issue lists have always answered.
 */
final readonly class Paging
{
    /**
     * @param positive-int $limit
     * @param int<0, max> $offset
     */
    public function __construct(
        public int $limit,
        public int $offset = 0,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in, int $default, int $max): self
    {
        return new self(
            limit: max(1, min($max, Input::int($in, 'limit', $default))),
            offset: max(0, Input::int($in, 'offset', 0)),
        );
    }
}
