<?php

declare(strict_types=1);

namespace Inventory;

/**
 * How far Rob's delete calls have got: how many marked databases the panel
 * still lists and how many a sweep has confirmed gone (mg #775).
 */
final readonly class MarkedSummary
{
    public function __construct(
        public int $total,
        public int $remaining,
    ) {
    }

    /**
     * @param list<array<mixed>> $markedRows Browse::markedDatabases(); 'present' is 1 while the panel still lists it
     */
    public static function fromRows(array $markedRows): self
    {
        $remaining = count(array_filter(
            $markedRows,
            static fn(array $row): bool => \Field::toInt($row['present'] ?? null) === 1,
        ));
        return new self(count($markedRows), $remaining);
    }

    /**
     * The delete calls worth showing now: the ones the panel still lists, and
     * the ones the latest imported sweep just confirmed gone. Older
     * confirmations have done their job and stay only in dh_triage.
     *
     * @param list<array<mixed>> $markedRows Browse::markedDatabases()
     * @return list<array<mixed>>
     */
    public static function visible(array $markedRows, ?string $latestSweepOn): array
    {
        return array_values(array_filter(
            $markedRows,
            static fn(array $row): bool => \Field::toInt($row['present'] ?? null) === 1
                || ($latestSweepOn !== null && \Field::toString($row['gone_on'] ?? null) === $latestSweepOn),
        ));
    }

    public function gone(): int
    {
        return $this->total - $this->remaining;
    }

    public function headline(): string
    {
        if ($this->remaining === 0) {
            return 'all ' . $this->total . ' confirmed gone';
        }
        return $this->gone() . ' of ' . $this->total . ' gone, ' . $this->remaining . ' still to delete';
    }
}
