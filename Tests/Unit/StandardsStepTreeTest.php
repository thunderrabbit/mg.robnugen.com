<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\StandardsFormatException;
use Inventory\Standards\StepModel;
use Inventory\Standards\StepStatus;
use Inventory\Standards\StepTree;
use Inventory\Standards\WhoseMove;

/**
 * Hermetic: turning the step files under tools/site-standards/steps/ into a
 * tree, and refusing the ones that would mislead the standards page.
 */
final class StandardsStepTreeTest extends Unit
{
    private const string STEP = 'template/rector/10_rector_php_8_5_rules.md';

    private const array GOOD = [
        'template/_standard.md' => "---\nname: Template sites\n---\n",
        'template/rector/_track.md' => "---\nname: Rector\nposition: 30\n---\n",
        self::STEP =>
            "---\ntitle: \"Rector's 8.5 rules, one per commit\"\nstatus: proven\n"
            . "whose_move: agent\ncheck: rector_level\nparam: 8.5\nmodel: sonnet\n---\nWhy it matters.\n\n"
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
        $this->assertSame(30.0, $track->position);
        $this->assertSame('rector_php_8_5_rules', $step->slug);
        $this->assertSame(10.0, $step->position);
        $this->assertSame("Rector's 8.5 rules, one per commit", $step->title);
        $this->assertSame(StepStatus::Proven, $step->status);
        $this->assertSame(WhoseMove::Agent, $step->whoseMove);
        $this->assertSame('rector_level', $step->checkName);
        $this->assertSame('8.5', $step->checkParam);
        $this->assertSame(StepModel::Sonnet, $step->model);
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
            'template/rector/20_a_later_step.md' => self::GOOD[self::STEP],
            'template/rector/5_an_earlier_step.md' => self::GOOD[self::STEP],
        ];

        $tracks = StepTree::fromFiles($files)->standards[0]->tracks;

        $this->assertSame(['groundwork', 'rector'], array_map(static fn (\Inventory\Standards\TrackDef $t): string => $t->slug, $tracks));
        $this->assertSame(
            ['an_earlier_step', 'rector_php_8_5_rules', 'a_later_step'],
            array_map(static fn (\Inventory\Standards\StepDef $s): string => $s->slug, $tracks[1]->steps),
        );
    }

    public function testAStepCanSitBetweenTwoWholePositions(): void
    {
        $files = self::GOOD + [
            'template/rector/10.0001_just_after.md' => self::GOOD[self::STEP],
            'template/rector/10.9999_just_before.md' => self::GOOD[self::STEP],
            'template/rector/11_whole_eleven.md' => self::GOOD[self::STEP],
            'template/rector/10.5_half_way.md' => self::GOOD[self::STEP],
        ];

        $steps = StepTree::fromFiles($files)->standards[0]->tracks[0]->steps;

        $this->assertSame(
            ['rector_php_8_5_rules', 'just_after', 'half_way', 'just_before', 'whole_eleven'],
            array_map(static fn (\Inventory\Standards\StepDef $s): string => $s->slug, $steps),
        );
        $this->assertSame(10.0001, $steps[1]->position);
    }

    public function testPositionZeroIsAllowed(): void
    {
        $files = self::GOOD + ['template/rector/000_first_of_all.md' => self::GOOD[self::STEP]];

        $steps = StepTree::fromFiles($files)->standards[0]->tracks[0]->steps;

        $this->assertSame('first_of_all', $steps[0]->slug);
        $this->assertSame(0.0, $steps[0]->position);
    }

    public function testRefusesAStepFileWithNoNumber(): void
    {
        $files = self::GOOD + ['template/rector/no_number.md' => self::GOOD[self::STEP]];

        $this->refuses($files, '#template/rector/no_number\.md.*<number>_<name>\.md#');
    }

    public function testRefusesAStepFileWithFiveDecimals(): void
    {
        $files = self::GOOD + ['template/rector/1.00001_too_fine.md' => self::GOOD[self::STEP]];

        $this->refuses($files, '#1\.00001_too_fine\.md#');
    }

    public function testRefusesAPositionLineInAStepFile(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("status: proven\n", "status: proven\nposition: 10\n", $files[self::STEP]);

        $this->refuses($files, '#10_rector_php_8_5_rules\.md.*position#');
    }

    public function testRefusesTheSameStepUnderTwoNumbers(): void
    {
        $files = self::GOOD + ['template/rector/20_rector_php_8_5_rules.md' => self::GOOD[self::STEP]];

        $this->refuses($files, '#rector_php_8_5_rules.*already used#');
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
            'template/tooling/10_rector_php_8_5_rules.md' => self::GOOD[self::STEP],
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

    public function testRefusesATrackPositionThatIsNotANumber(): void
    {
        $files = self::GOOD;
        $files['template/rector/_track.md'] = "---\nname: Rector\nposition: first\n---\n";

        $this->refuses($files, '/position/');
    }

    public function testRefusesAStepNameWithAHyphen(): void
    {
        $files = self::GOOD;
        $files['template/rector/10_has-hyphen.md'] = $files[self::STEP];

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
        $this->refuses(self::GOOD + ['template/rector/deeper/10_extra.md' => self::GOOD[self::STEP]], '/deeper/');
    }

    public function testAStepTakesItsTracksModelWhenItNamesNone(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("model: sonnet\n", '', $files[self::STEP]);
        $files['template/rector/_track.md'] = "---\nname: Rector\nposition: 30\nmodel: opus\n---\n";

        $this->assertSame(StepModel::Opus, StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model);
    }

    public function testAStepsOwnModelBeatsItsTracks(): void
    {
        $files = self::GOOD;
        $files['template/rector/_track.md'] = "---\nname: Rector\nposition: 30\nmodel: opus\n---\n";

        $this->assertSame(StepModel::Sonnet, StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model);
    }

    public function testEveryModelNameIsAccepted(): void
    {
        foreach (['haiku', 'sonnet', 'opus', 'fable'] as $name) {
            $files = self::GOOD;
            $files[self::STEP] = str_replace('model: sonnet', "model: {$name}", $files[self::STEP]);

            $this->assertSame($name, StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model?->value);
        }
    }

    public function testRefusesAnUnknownModelOnAStep(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace('model: sonnet', 'model: gpt', $files[self::STEP]);

        $this->refuses($files, '#10_rector_php_8_5_rules\.md: model must be haiku, sonnet, opus or fable#');
    }

    public function testRefusesAModelInCapitals(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace('model: sonnet', 'model: Sonnet', $files[self::STEP]);

        $this->refuses($files, '/model must be/');
    }

    public function testRefusesAnUnknownModelOnATrackEvenWithNoSteps(): void
    {
        $files = self::GOOD + [
            'template/groundwork/_track.md' => "---\nname: Groundwork\nposition: 10\nmodel: best\n---\n",
        ];

        $this->refuses($files, '#template/groundwork/_track\.md: model must be#');
    }

    public function testRefusesAModelOnAStandard(): void
    {
        $files = self::GOOD;
        $files['template/_standard.md'] = "---\nname: Template sites\nmodel: sonnet\n---\n";

        $this->refuses($files, '#_standard\.md: unknown frontmatter key "model"#');
    }

    public function testRobsStepMayNameNoModel(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace(
            ['whose_move: agent', "model: sonnet\n"],
            ['whose_move: rob', ''],
            $files[self::STEP],
        );

        $this->assertNull(StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model);
    }

    public function testRefusesAnAgentStepWithNoModel(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("model: sonnet\n", '', $files[self::STEP]);

        $this->refuses($files, '#10_rector_php_8_5_rules\.md: an agent step needs a model#');
    }

    public function testRobsStepInATrackWithAModelIsAccepted(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace(
            ['whose_move: agent', "model: sonnet\n"],
            ['whose_move: rob', ''],
            $files[self::STEP],
        );
        $files['template/rector/_track.md'] = "---\nname: Rector\nposition: 30\nmodel: opus\n---\n";

        $this->assertSame(StepModel::Opus, StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model);
    }

    public function testAnAgentStepMaySayItDeploys(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("model: sonnet\n", "model: sonnet\ndeploys: agent\n", $files[self::STEP]);

        $this->assertSame('rector_php_8_5_rules', StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->slug);
    }

    public function testRefusesADeploysLineNamingAnyoneElse(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("model: sonnet\n", "model: sonnet\ndeploys: rob\n", $files[self::STEP]);

        $this->refuses($files, '#10_rector_php_8_5_rules\.md: deploys must be agent, or the line left out#');
    }

    public function testRefusesDeploysOnRobsStep(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace(
            ['whose_move: agent', "model: sonnet\n"],
            ['whose_move: rob', "deploys: agent\n"],
            $files[self::STEP],
        );

        $this->refuses($files, '#10_rector_php_8_5_rules\.md: only an agent\'s step may say "deploys: agent"#');
    }
}
