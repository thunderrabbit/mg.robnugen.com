<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\StepLine;
use Inventory\Standards\TrackLines;
use Inventory\Standards\WhoseMove;

/**
 * Hermetic: how the standards page folds a site's finished steps.
 */
final class StandardsTrackLinesTest extends Unit
{
    private function line(string $track, string $slug, bool $done): StepLine
    {
        return new StepLine($slug, $track, 'A step', WhoseMove::Agent, $done, '', false, [], 'Why.', 'How.', 'a_check', null);
    }

    /**
     * @param list<StepLine> $lines
     * @return list<string>
     */
    private function slugs(array $lines): array
    {
        return array_map(static fn (StepLine $line): string => $line->slug, $lines);
    }

    public function testNoLinesGiveNoTracks(): void
    {
        $this->assertSame([], TrackLines::group([]));
    }

    public function testTracksKeepTheOrderTheyArriveIn(): void
    {
        $tracks = TrackLines::group([
            $this->line('Groundwork', 'a', true),
            $this->line('Tooling', 'b', false),
            $this->line('PHPStan', 'c', true),
        ]);

        $this->assertSame(['Groundwork', 'Tooling', 'PHPStan'], array_map(
            static fn (TrackLines $track): string => $track->name,
            $tracks,
        ));
    }

    public function testFinishedStepsAreSetApartFromOpenOnesInOrder(): void
    {
        $tracks = TrackLines::group([
            $this->line('PHPStan', 'level_0', true),
            $this->line('PHPStan', 'level_1', false),
            $this->line('PHPStan', 'level_2', true),
            $this->line('PHPStan', 'level_3', false),
        ]);

        $this->assertCount(1, $tracks);
        $this->assertSame(['level_0', 'level_2'], $this->slugs($tracks[0]->done));
        $this->assertSame(['level_1', 'level_3'], $this->slugs($tracks[0]->open));
    }

    public function testATrackWithNothingFinishedHasNothingToFold(): void
    {
        $tracks = TrackLines::group([$this->line('Security', 'a', false)]);

        $this->assertSame([], $tracks[0]->done);
        $this->assertSame(['a'], $this->slugs($tracks[0]->open));
    }

    public function testTheFoldedLineCountsWhatIsFinished(): void
    {
        $tracks = TrackLines::group([
            $this->line('Mixed', 'a', true),
            $this->line('Mixed', 'b', true),
            $this->line('Mixed', 'c', false),
            $this->line('Finished', 'd', true),
            $this->line('Finished', 'e', true),
            $this->line('One', 'f', true),
        ]);

        $this->assertSame('2 done', $tracks[0]->doneSummary());
        $this->assertSame('all 2 done', $tracks[1]->doneSummary());
        $this->assertSame('1 done', $tracks[2]->doneSummary());
    }
}
