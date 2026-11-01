<?php

declare(strict_types=1);

namespace Tests\Unit;

use ActivityTracking\DailyCap;
use Codeception\Test\Unit;

class DailyCapTest extends Unit
{
    public function testLogicalDayShiftsThreeHours(): void
    {
        $this->assertSame('2026-11-01', DailyCap::logicalDay(new \DateTimeImmutable('2026-11-02 02:59:00')));
        $this->assertSame('2026-11-02', DailyCap::logicalDay(new \DateTimeImmutable('2026-11-02 03:00:00')));
    }

    public function testReached(): void
    {
        $this->assertFalse(DailyCap::reached(1, 2));
        $this->assertTrue(DailyCap::reached(2, 2));
        $this->assertTrue(DailyCap::reached(1, null));
        $this->assertFalse(DailyCap::reached(0, null));
        $this->assertTrue(DailyCap::reached(1, 0));
    }
}
