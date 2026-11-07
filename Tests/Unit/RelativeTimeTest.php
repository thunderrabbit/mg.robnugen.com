<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class RelativeTimeTest extends Unit
{
    private const int DAY = 86400;

    /**
     * @return array<string, array{int, string}>
     */
    public static function offsets(): array
    {
        return [
            'now' => [0, 'just now'],
            'a second ago' => [-1, '1 second ago'],
            'seconds ago' => [-45, '45 seconds ago'],
            'a minute ago' => [-60, '1 minute ago'],
            'minutes ago' => [-59 * 60, '59 minutes ago'],
            'an hour ago' => [-3600, '1 hour ago'],
            'ninety minutes floors to an hour' => [-90 * 60, '1 hour ago'],
            'hours ago' => [-23 * 3600, '23 hours ago'],
            'a day ago' => [-self::DAY, '1 day ago'],
            'days ago' => [-9 * self::DAY, '9 days ago'],
            'a month ago' => [-30 * self::DAY, '1 month ago'],
            'months ago' => [-70 * self::DAY, '2 months ago'],
            'a year ago' => [-400 * self::DAY, '1 year ago'],
            'in a second' => [1, 'in 1 second'],
            'in days' => [20 * self::DAY, 'in 20 days'],
        ];
    }

    /**
     * @dataProvider offsets
     */
    public function testDescribesTheSpanInWords(int $offset_seconds, string $expected): void
    {
        $now = new \DateTimeImmutable('2026-11-01 09:38:00', new \DateTimeZone('UTC'));
        $then = $now->modify("{$offset_seconds} seconds");
        $this->assertSame($expected, \RelativeTime::describe($then, $now));
    }

    public function testComparesInstantsNotWallClocks(): void
    {
        // The same instant in two zones is "just now", not "nine hours ago".
        $utc = new \DateTimeImmutable('2026-11-01 09:38:00', new \DateTimeZone('UTC'));
        $sydney = new \DateTimeImmutable('2026-11-01 20:38:00', new \DateTimeZone('Australia/Sydney'));
        $this->assertSame('just now', \RelativeTime::describe($utc, $sydney));
    }
}
