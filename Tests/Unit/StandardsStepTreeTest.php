<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\StandardsFormatException;
use Inventory\Standards\StepStatus;
use Inventory\Standards\StepTree;
use Inventory\Standards\WhoseMove;

/**
 * Hermetic: turning the step files under tools/site-standards/steps/ into a
 * tree, and refusing the ones that would mislead the standards page.
 */
final class StandardsStepTreeTest extends Unit
{
    private const string STEP = 'template/rector/rector_php_8_5_rules.md';

    private const array GOOD = [
        'template/_standard.md' => "---\nname: Template sites\n---\n",
        'template/rector/_track.md' => "---\nname: Rector\nposition: 30\n---\n",
        self::STEP =>
            "---\ntitle: \"Rector's 8.5 rules, one per commit\"\nposition: 10\nstatus: proven\n"
            . "whose_move: agent\ncheck: rector_level\nparam: 8.5\n---\nWhy it matters.\n\n"
            . "## How to do it\n\n1. Do this.\n",
    ];

    /**
     * @param array<string, string> $files
     */
    private function refuses(array $files, string $pattern): void
    {
        try {
            StepTree::fromFiles($files);
        } catch (StandardsFormatException $e) {
            $this->assertMatchesRegularExpression($pattern, $e->getMessage());
            return;
        }
        $this->fail('The step files were accepted.');
    }

    public function testParsesAStandardATrackAndAStep(): void
    {
        $standard = StepTree::fromFiles(self::GOOD)->standards[0];
        $track = $standard->tracks[0];
        $step = $track->steps[0];

        $this->assertSame('template', $standard->slug);
        $this->assertSame('Template sites', $standard->name);
        $this->assertSame('rector', $track->slug);
        $this->assertSame('Rector', $track->name);
        $this->assertSame(30, $track->position);
        $this->assertSame('rector_php_8_5_rules', $step->slug);
        $this->assertSame(10, $step->position);
        $this->assertSame("Rector's 8.5 rules, one per commit", $step->title);
        $this->assertSame(StepStatus::Proven, $step->status);
        $this->assertSame(WhoseMove::Agent, $step->whoseMove);
        $this->assertSame('rector_level', $step->checkName);
        $this->assertSame('8.5', $step->checkParam);
        $this->assertSame('Why it matters.', $step->explanation);
        $this->assertSame('1. Do this.', $step->howTo);
    }

    public function testAStepWithNoHowToHeadingIsAllExplanation(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("\n## How to do it\n\n1. Do this.\n", '', $files[self::STEP]);

        $step = StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0];

        $this->assertSame('Why it matters.', $step->explanation);
        $this->assertSame('', $step->howTo);
    }

    public function testParamIsOptional(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("param: 8.5\n", '', $files[self::STEP]);

        $this->assertNull(StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->checkParam);
    }

    public function testTracksAndStepsComeBackInPositionOrder(): void
    {
        $files = self::GOOD + [
            'template/groundwork/_track.md' => "---\nname: Groundwork\nposition: 10\n---\n",
            'template/rector/a_later_step.md' => str_replace('position: 10', 'position: 20', self::GOOD[self::STEP]),
            'template/rector/an_earlier_step.md' => str_replace('position: 10', 'position: 5', self::GOOD[self::STEP]),
        ];

        $tracks = StepTree::fromFiles($files)->standards[0]->tracks;

        $this->assertSame(['groundwork', 'rector'], array_map(static fn ($t) => $t->slug, $tracks));
        $this->assertSame(
            ['an_earlier_step', 'rector_php_8_5_rules', 'a_later_step'],
            array_map(static fn ($s) => $s->slug, $tracks[1]->steps),
        );
    }

    public function testARemovedStepIsSimplyAbsentFromTheTree(): void
    {
        $files = self::GOOD;
        unset($files[self::STEP]);

        $this->assertSame([], StepTree::fromFiles($files)->standards[0]->tracks[0]->steps);
    }

    public function testNoFilesIsAnEmptyTree(): void
    {
        $this->assertSame([], StepTree::fromFiles([])->standards);
    }

    public function testRefusesTheSameStepNameTwiceInOneStandard(): void
    {
        $this->refuses(self::GOOD + [
            'template/tooling/_track.md' => "---\nname: Tooling\nposition: 20\n---\n",
            'template/tooling/rector_php_8_5_rules.md' => self::GOOD[self::STEP],
        ], '/rector_php_8_5_rules/');
    }

    public function testRefusesATrackWithNoTrackFile(): void
    {
        $files = self::GOOD;
        unset($files['template/rector/_track.md']);

        $this->refuses($files, '#template/rector/_track\.md#');
    }

    public function testRefusesAStandardWithNoStandardFile(): void
    {
        $files = self::GOOD;
        unset($files['template/_standard.md']);

        $this->refuses($files, '#template/_standard\.md#');
    }

    public function testRefusesAnUnknownStatus(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace('status: proven', 'status: done', $files[self::STEP]);

        $this->refuses($files, '/status/');
    }

    public function testRefusesAnUnknownFrontmatterKey(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("param: 8.5\n", "param: 8.5\nowner: someone\n", $files[self::STEP]);

        $this->refuses($files, '/owner/');
    }

    public function testRefusesAMissingKey(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("check: rector_level\n", '', $files[self::STEP]);

        $this->refuses($files, '/check/');
    }

    public function testRefusesAPositionThatIsNotAPositiveInteger(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace('position: 10', 'position: first', $files[self::STEP]);

        $this->refuses($files, '/position/');
    }

    public function testRefusesAStepNameWithAHyphen(): void
    {
        $files = self::GOOD;
        $files['template/rector/has-hyphen.md'] = $files[self::STEP];

        $this->refuses($files, '/has-hyphen/');
    }

    public function testRefusesAFileWithNoFrontmatter(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = "Just some words.\n";

        $this->refuses($files, '/rector_php_8_5_rules\.md/');
    }

    public function testRefusesAFileAtTheWrongDepth(): void
    {
        $this->refuses(self::GOOD + ['template/rector/deeper/extra.md' => self::GOOD[self::STEP]], '/deeper/');
    }
}
