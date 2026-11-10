<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inbox\CreatedRange;

/**
 * The created_after / created_before window on /inbox/list. Hermetic:
 * strings in, UTC strings out.
 */
class InboxCreatedRangeTest extends Unit
{
    public function testLeftOutOrBlankIsNoBound(): void
    {
        $this->assertNull(CreatedRange::bound('created_after', ''));
        $this->assertNull(CreatedRange::bound('created_after', '  '));
    }

    public function testBareDateIsMidnightUtc(): void
    {
        $this->assertSame('2026-11-10 00:00:00', CreatedRange::bound('created_after', '2026-11-10'));
    }

    public function testDatetimeForms(): void
    {
        $this->assertSame('2026-11-10 09:30:00', CreatedRange::bound('created_after', '2026-11-10T09:30'));
        $this->assertSame('2026-11-10 09:30:15', CreatedRange::bound('created_after', '2026-11-10T09:30:15'));
        $this->assertSame('2026-11-10 09:30:15', CreatedRange::bound('created_after', '2026-11-10T09:30:15Z'));
        $this->assertSame('2026-11-10 09:30:15', CreatedRange::bound('created_after', '2026-11-10 09:30:15'));
    }

    public function testOffsetsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('created_before must be');
        CreatedRange::bound('created_before', '2026-11-10T09:30:00+11:00');
    }

    public function testNonsenseIsRefused(): void
    {
        foreach (['yesterday', '2026-11', '10/11/2026', '2026-11-10T9:30'] as $bad) {
            try {
                CreatedRange::bound('created_after', $bad);
                $this->fail("{$bad} should have been refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testImpossibleDatesAreRefusedNotRolledOver(): void
    {
        foreach (['2026-02-31', '2026-13-01', '2026-11-10T25:00', '2026-11-10T09:61'] as $bad) {
            try {
                CreatedRange::bound('created_after', $bad);
                $this->fail("{$bad} should have been refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWindowReturnsBothBounds(): void
    {
        $this->assertSame(
            ['2026-11-09 00:00:00', '2026-11-10 00:00:00'],
            CreatedRange::window('2026-11-09', '2026-11-10'),
        );
        $this->assertSame([null, '2026-11-10 00:00:00'], CreatedRange::window('', '2026-11-10'));
        $this->assertSame(['2026-11-09 00:00:00', null], CreatedRange::window('2026-11-09', ''));
        $this->assertSame([null, null], CreatedRange::window('', ''));
    }

    public function testEmptyWindowIsRefused(): void
    {
        foreach ([['2026-11-10', '2026-11-10'], ['2026-11-11', '2026-11-10']] as [$after, $before]) {
            try {
                CreatedRange::window($after, $before);
                $this->fail('an empty window should have been refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('earlier than', $e->getMessage());
            }
        }
    }
}
