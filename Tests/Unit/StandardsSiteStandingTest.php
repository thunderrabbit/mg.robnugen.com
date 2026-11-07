<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\Mark;
use Inventory\Standards\SiteStanding;
use Inventory\Standards\StepLine;
use Inventory\Standards\WhoseMove;

/**
 * Hermetic: which of the three marks a site carries, and its one-line summary.
 */
final class StandardsSiteStandingTest extends Unit
{
    private function line(bool $done, WhoseMove $whose = WhoseMove::Agent): StepLine
    {
        return new StepLine('a_step', 'A track', 'A step', $whose, $done, '', false, [], '', '', 'a_check', null);
    }

    public function testUnreachableComesFirstEvenWhenEverythingElseIsDone(): void
    {
        $standing = SiteStanding::of(false, [$this->line(true)]);

        $this->assertSame(Mark::Face, $standing->mark);
        $this->assertSame("can't reach it yet", $standing->summary);
    }

    public function testAllDoneIsTheCheckmark(): void
    {
        $standing = SiteStanding::of(true, [$this->line(true), $this->line(true, WhoseMove::Rob)]);

        $this->assertSame(Mark::Check, $standing->mark);
        $this->assertSame('up to date', $standing->summary);
    }

    public function testNoProvenStepsIsUpToDate(): void
    {
        $standing = SiteStanding::of(true, []);

        $this->assertSame(Mark::Check, $standing->mark);
        $this->assertSame('up to date', $standing->summary);
    }

    public function testRobsOpenMoveWins(): void
    {
        $standing = SiteStanding::of(true, [$this->line(false), $this->line(false, WhoseMove::Rob)]);

        $this->assertSame(Mark::Info, $standing->mark);
        $this->assertSame('waiting on you', $standing->summary);
    }

    public function testRobsDoneMoveDoesNotCountAsWaiting(): void
    {
        $standing = SiteStanding::of(true, [$this->line(false), $this->line(true, WhoseMove::Rob)]);

        $this->assertSame('1 step to go', $standing->summary);
    }

    public function testCountsOpenSteps(): void
    {
        $one = SiteStanding::of(true, [$this->line(false), $this->line(true)]);
        $three = SiteStanding::of(true, [$this->line(false), $this->line(false), $this->line(false)]);

        $this->assertSame(Mark::Info, $one->mark);
        $this->assertSame('1 step to go', $one->summary);
        $this->assertSame('3 steps to go', $three->summary);
    }

    public function testTheThreeMarksAreACheckAnInfoCircleAndALaughingFace(): void
    {
        $glyphs = array_map(static fn (Mark $mark): string => $mark->glyph(), Mark::cases());

        $this->assertSame(['✓', 'ⓘ', '😂'], $glyphs);
        $this->assertSame('StdMark StdMark--face', Mark::Face->cssClass());
    }
}
