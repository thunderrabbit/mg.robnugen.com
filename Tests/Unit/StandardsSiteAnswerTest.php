<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\Names;
use Inventory\Standards\NoteIssue;
use Inventory\Standards\SiteAnswer;
use Inventory\Standards\SiteFacts;
use Inventory\Standards\SiteReport;

/**
 * Hermetic: one site's open steps shaped for the API, from plain rows.
 * Sites and issues are made up.
 *
 * @phpstan-import-type Answer from SiteAnswer
 */
final class StandardsSiteAnswerTest extends Unit
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

    /**
     * @param list<array<string, string|null>> $steps
     * @param array<string, array{outcome: string, detail: string}> $states
     * @param list<NoteIssue> $issues
     * @return Answer
     */
    private function answer(array $steps, array $states = [], array $issues = []): array
    {
        $slugs = array_map(static fn (array $s): string => (string) $s['slug'], $steps);
        $report = SiteReport::build('mg', $this->reachableFacts(), $steps, $states, $issues, ['mg'], $slugs);
        return SiteAnswer::of('template', 'mg', '2026-11-07 03:12', null, $report, '8.2', '8.5');
    }

    public function testTheAnswerSaysWhichPhpServesTheSite(): void
    {
        $answer = $this->answer([$this->step(self::STEP, 'phpstan_top')]);

        $this->assertSame('8.2', $answer['php_current']);
        $this->assertSame('8.5', $answer['test_php']);
    }

    public function testAnUnsweptSiteHasNoPhpToName(): void
    {
        $report = SiteReport::build('mg', $this->reachableFacts(), [], [], [], ['mg'], []);
        $answer = SiteAnswer::of('template', 'mg', null, null, $report, null, null);

        $this->assertNull($answer['php_current']);
        $this->assertNull($answer['test_php']);
    }

    public function testAnUpToDateSiteHasNoOpenSteps(): void
    {
        $answer = $this->answer(
            [$this->step(self::STEP, 'phpstan_top')],
            [self::STEP => ['outcome' => 'pass', 'detail' => 'clean']],
        );

        $this->assertSame([], $answer['open_steps']);
        $this->assertSame(['mark' => 'check', 'text' => 'up to date'], $answer['standing']);
        $this->assertSame('mg', $answer['shortcode']);
        $this->assertSame('template', $answer['standard']);
        $this->assertSame('2026-11-07 03:12', $answer['last_checked_utc']);
        $this->assertNull($answer['skipped_reason']);
    }

    public function testAFailingStepIsOpenWithEverythingTheAgentNeeds(): void
    {
        $answer = $this->answer(
            [$this->step(self::STEP, 'phpstan_top', '10')],
            [self::STEP => ['outcome' => 'fail', 'detail' => 'not yet']],
        );

        $this->assertSame([[
            'slug' => self::STEP,
            'track' => 'A track',
            'title' => 'A step',
            'whose_move' => 'agent',
            'detail' => 'not yet',
            'check' => 'phpstan_top',
            'check_param' => '10',
            'explanation' => 'Why.',
            'how_to' => 'How.',
        ]], $answer['open_steps']);
    }

    public function testANahStepIsNotOpen(): void
    {
        $answer = $this->answer(
            [$this->step(self::STEP, 'phpstan_top')],
            [self::STEP => ['outcome' => 'fail', 'detail' => 'not yet']],
            [new NoteIssue(1, Names::noteTitle(self::STEP, 'mg'), 'nah', 'NAh')],
        );

        $this->assertSame([], $answer['open_steps']);
    }

    public function testANeverScannedStepIsOpen(): void
    {
        $answer = $this->answer([$this->step(self::STEP, 'phpstan_top')]);

        $this->assertCount(1, $answer['open_steps']);
        $this->assertSame('not checked yet', $answer['open_steps'][0]['detail']);
    }

    public function testAnUnknownOutcomeIsOpen(): void
    {
        $answer = $this->answer(
            [$this->step(self::STEP, 'phpstan_top')],
            [self::STEP => ['outcome' => 'unknown', 'detail' => 'could not tell']],
        );

        $this->assertCount(1, $answer['open_steps']);
        $this->assertSame('could not tell', $answer['open_steps'][0]['detail']);
    }

    public function testOpenStepsKeepDisplayOrder(): void
    {
        $answer = $this->answer(
            [$this->step('a_agent', 'check_a'), $this->step('b_rob', 'check_b', null, 'rob')],
            [
                'a_agent' => ['outcome' => 'fail', 'detail' => ''],
                'b_rob' => ['outcome' => 'fail', 'detail' => ''],
            ],
        );

        $this->assertSame(['a_agent', 'b_rob'], array_column($answer['open_steps'], 'slug'));
        $this->assertSame(['agent', 'rob'], array_column($answer['open_steps'], 'whose_move'));
        $this->assertSame(['mark' => 'info', 'text' => 'waiting on you'], $answer['standing']);
    }

    public function testTheAnswerCarriesNoHostingFacts(): void
    {
        $json = (string) json_encode($this->answer([$this->step(self::STEP, 'phpstan_top')]));

        $this->assertStringNotContainsString('example.test', $json);
        $this->assertStringNotContainsString('exampleuser', $json);
        $this->assertStringNotContainsString('"ssh_alias"', $json);
    }

    public function testHostCheckDetailsAndSkipReasonsNameNoHost(): void
    {
        $facts = new SiteFacts(
            [[
                'domain' => 'example.test',
                'php_version' => '8.2',
                'username' => 'exampleuser',
                'ssh_alias' => 'example',
                'login_confirmed_on' => null,
            ]],
            'rob/example.test',
            'example/example.test',
        );
        $steps = [$this->step('ssh_login_works', 'dh_ssh_login'), $this->step('serves_php_8_5', 'dh_serves_php', '8.5')];
        $report = SiteReport::build('mg', $facts, $steps, [], [], ['mg'], ['ssh_login_works', 'serves_php_8_5']);
        $answer = SiteAnswer::of('template', 'mg', null, 'no ssh alias is known for the user serving example.test', $report, '8.2', null);

        $this->assertCount(2, $answer['open_steps']);
        $this->assertSame(SiteAnswer::HOST_DETAIL, $answer['open_steps'][0]['detail']);
        $this->assertSame(SiteAnswer::SKIPPED, $answer['skipped_reason']);
        $json = (string) json_encode($answer);
        $this->assertStringNotContainsString('example.test', $json);
        $this->assertStringNotContainsString('exampleuser', $json);
    }
}
