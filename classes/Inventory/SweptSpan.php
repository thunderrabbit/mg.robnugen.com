<?php

declare(strict_types=1);

namespace Inventory;

/**
 * One thing a sweep says is true right now: the columns that identify it
 * (usually one foreign key) and the attributes that may change from sweep to
 * sweep. Attributes are normalised to string|null so a TINYINT read back from
 * MySQL compares equal to the bool the sweep carried.
 */
final readonly class SweptSpan
{
    /** @var array<string, string|null> */
    public array $attrs;

    /**
     * @param array<string, int|string> $key   column => value, in key order
     * @param array<string, bool|int|string|null> $attrs column => value
     */
    public function __construct(public array $key, array $attrs)
    {
        $normalised = [];
        foreach ($attrs as $column => $value) {
            $normalised[$column] = SpanPlan::normalise($value);
        }
        $this->attrs = $normalised;
    }

    public function keyString(): string
    {
        return SpanPlan::keyString($this->key);
    }
}
