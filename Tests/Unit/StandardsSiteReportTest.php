<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\Mark;
use Inventory\Standards\NoteIssue;
use Inventory\Standards\SiteFacts;
use Inventory\Standards\SiteReport;

/**
 * Hermetic: one site's steps, notes and mark, put together from plain rows.
 * Sites and issues are made up.
 */
final class StandardsSiteReportTest extends Unit
{
    private const string STEP = 'phpstan_level_10_no_baseline';

    private function reachableFacts(): SiteFacts
    {
        return new SiteFacts(
            [[
                'domain' => 'example.test',
                'php_version' => '8.5',
                'username' => 'exampleuser',
                'ssh_alias' => 'example',
                'login_confirmed_on' => '2026-11-07',
            ]],
            'rob/example.test',
            'example/example.test',
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function step(string $slug, string $check, ?string $param = null, string $whose = 'agent'): array
    {
        return [
            'slug' => $slug,
            'track_name' => 'A track',
            'title' => 'A step',
            'whose_move' => $whose,
            'check_name' => $check,
            'check_param' => $param,
            'explanation' => 'Why.',
            'how_to' => 'How.',
        ];
    }

    private function issue(int $id, string $title, string $status): NoteIssue
    {
        return new NoteIssue($id, $title, $status, ucfirst($status));
    }

    /**
     * @param array<string, array{outcome: string, detail: string}> $states
     * @param list<NoteIssue> $issues
     */
    private function report(array $states = [], array $issues = [], ?SiteFacts $facts = null): SiteReport
    {
        return SiteReport::build(
            'mg',
            $facts ?? $this->reachableFacts(),
            [$this->step(self::STEP, 'phpstan_top')],
            $states,
            $issues,
            ['mg'],
            [self::STEP, 'an_idea_step'],
        );
    }

    public function testAScannerStepNeverScannedReadsNotCheckedYet(): void
    {
        $report = $this->report();

        $this->assertFalse($report->lines[0]->done);
        $this->assertSame('not checked yet', $report->lines[0]->detail);
        $this->assertSame(Mark::Info, $report->standing->mark);
        $this->assertSame('1 step to go', $report->standing->summary);
    }

    public function testAPassingStateTicksTheStep(): void
    {
        $report = $this->report([self::STEP => ['outcome' => 'pass', 'detail' => 'level 10, run clean']]);

        $this->assertTrue($report->lines[0]->done);
        $this->assertSame('level 10, run clean', $report->lines[0]->detail);
        $this->assertSame(Mark::Check, $report->standing->mark);
    }

    public function testAFailingOrUnknownStateLeavesTheStepOpenWithItsDetail(): void
    {
        $failed = $this->report([self::STEP => ['outcome' => 'fail', 'detail' => 'level is 8']]);
        $unknown = $this->report([self::STEP => ['outcome' => 'unknown', 'detail' => 'vendor is missing']]);

        $this->assertFalse($failed->lines[0]->done);
        $this->assertSame('level is 8', $failed->lines[0]->detail);
        $this->assertFalse($unknown->lines[0]->done);
        $this->assertSame('vendor is missing', $unknown->lines[0]->detail);
        $this->assertSame(Mark::Info, $unknown->standing->mark);
    }

    public function testAStepMgAnswersItselfNeedsNoScan(): void
    {
        $report = SiteReport::build(
            'mg',
            $this->reachableFacts(),
            [$this->step('serves_php_8_5', 'dh_serves_php', '8.5', 'rob')],
            [],
            [],
            ['mg'],
            ['serves_php_8_5'],
        );

        $this->assertTrue($report->lines[0]->done);
        $this->assertSame(Mark::Check, $report->standing->mark);
    }

    public function testAnUnreachableSiteGetsTheFaceAndStillShowsItsSteps(): void
    {
        $report = $this->report(
            [self::STEP => ['outcome' => 'pass', 'detail' => '']],
            [],
            new SiteFacts([], 'rob/example.test', 'example/example.test'),
        );

        $this->assertSame(Mark::Face, $report->standing->mark);
        $this->assertCount(1, $report->lines);
        $this->assertTrue($report->lines[0]->done);
    }

    public function testAnIssueMarkedDoneDoesNotTickTheStep(): void
    {
        $report = $this->report([], [$this->issue(812, self::STEP . '.mg', 'done')]);

        $this->assertFalse($report->lines[0]->done);
        $this->assertFalse($report->lines[0]->nah);
        $this->assertCount(1, $report->lines[0]->issues);
        $this->assertSame(812, $report->lines[0]->issues[0]->issueId);
    }

    public function testNahCountsAsDone(): void
    {
        $report = $this->report([], [$this->issue(813, self::STEP . '.mg', 'nah')]);

        $this->assertTrue($report->lines[0]->done);
        $this->assertTrue($report->lines[0]->nah);
        $this->assertSame(Mark::Check, $report->standing->mark);
    }

    public function testTwoIssuesWithTheSameTitleBothShow(): void
    {
        $report = $this->report([], [
            $this->issue(814, self::STEP . '.mg', 'open'),
            $this->issue(815, self::STEP . '.mg', 'nah'),
        ]);

        $this->assertCount(2, $report->lines[0]->issues);
        $this->assertTrue($report->lines[0]->done);
        $this->assertSame([], $report->strays);
    }

    public function testANoteForAnotherSiteIsAStray(): void
    {
        $report = $this->report([], [$this->issue(816, self::STEP . '.example-games', 'open')]);

        $this->assertSame([], $report->lines[0]->issues);
        $this->assertCount(1, $report->strays);
        $this->assertSame(816, $report->strays[0]->issueId);
    }

    public function testANoteForASiteSharingTheProjectIsNotAStray(): void
    {
        $report = SiteReport::build(
            'mg',
            $this->reachableFacts(),
            [$this->step(self::STEP, 'phpstan_top')],
            [],
            [$this->issue(817, self::STEP . '.example-games', 'open')],
            ['mg', 'example-games'],
            [self::STEP],
        );

        $this->assertSame([], $report->lines[0]->issues);
        $this->assertSame([], $report->strays);
    }

    public function testANoteForARenamedStepIsAStray(): void
    {
        $report = $this->report([], [$this->issue(818, 'old_name.mg', 'open')]);

        $this->assertCount(1, $report->strays);
        $this->assertSame('old_name.mg', $report->strays[0]->title);
    }

    public function testANoteForAnIdeaStepIsNotAStray(): void
    {
        $report = $this->report([], [$this->issue(819, 'an_idea_step.mg', 'open')]);

        $this->assertSame([], $report->strays);
    }

    public function testOrdinaryIssuesAreIgnored(): void
    {
        $report = $this->report([], [$this->issue(820, 'Fix the login page', 'open')]);

        $this->assertSame([], $report->strays);
        $this->assertSame([], $report->lines[0]->issues);
    }

    public function testASiteWithNoProvenStepsIsUpToDate(): void
    {
        $report = SiteReport::build('mg', $this->reachableFacts(), [], [], [], ['mg'], []);

        $this->assertSame([], $report->lines);
        $this->assertSame(Mark::Check, $report->standing->mark);
    }

    public function testANoteOnAnIdeaStepIsKeptForTheIdeasList(): void
    {
        $report = $this->report([], [$this->issue(821, 'an_idea_step.mg', 'in_progress')]);

        $this->assertSame([], $report->strays);
        $this->assertSame([], $report->lines[0]->issues);
        $this->assertCount(1, $report->ideaNotes['an_idea_step']);
        $this->assertSame(821, $report->ideaNotes['an_idea_step'][0]->issueId);
    }

    public function testNoNotesOnIdeasIsAnEmptyList(): void
    {
        $this->assertSame([], $this->report()->ideaNotes);
    }
}
