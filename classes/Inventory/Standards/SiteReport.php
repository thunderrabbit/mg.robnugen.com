<?php

declare(strict_types=1);

namespace Inventory\Standards;

use Db\RowValue;

/**
 * Everything the standards page says about one site, put together from plain
 * rows so it can be tested without a database.
 */
final readonly class SiteReport
{
    public const string NOT_CHECKED = 'not checked yet';

    /**
     * @param list<StepLine> $lines one per proven step, in the order given
     * @param list<NoteIssue> $strays issues titled like a site note that match no site or step here
     * @param array<string, list<NoteIssue>> $ideaNotes this site's notes on steps that are still
     *     ideas, by step slug: the sign that this site is the one practising the idea
     * @param array<string, string> $outcomes the scanner's current outcome ("pass", "fail" or
     *     "unknown") for every step it has a word on, by step slug
     */
    private function __construct(
        public Standing $standing,
        public array $lines,
        public array $strays,
        public array $ideaNotes,
        private array $outcomes,
    ) {
    }

    /**
     * What the scanner last said about a step on this site, or null when it
     * has said nothing (never checked, or a check mg answers without it).
     */
    public function openOutcome(string $slug): ?string
    {
        return $this->outcomes[$slug] ?? null;
    }

    /**
     * @param list<array<mixed>> $stepRows proven, unretired steps of the site's standard, in display
     *     order: slug, track_name, title, whose_move, check_name, check_param, explanation, how_to
     * @param array<string, array{outcome: string, detail: string}> $openStates the scanner's current
     *     word on this site, by step slug
     * @param list<NoteIssue> $projectIssues issues in the site's project
     * @param list<string> $shortcodesInProject every site whose notes live in that project
     * @param list<string> $knownSteps every unretired step name of the standard, ideas included
     */
    public static function build(
        string $shortcode,
        SiteFacts $facts,
        array $stepRows,
        array $openStates,
        array $projectIssues,
        array $shortcodesInProject,
        array $knownSteps,
    ): self {
        $notes = [];
        $strays = [];
        foreach ($projectIssues as $issue) {
            $named = Names::parseNoteTitle($issue->title);
            if ($named === null) {
                continue;
            }
            if ($named['shortcode'] !== $shortcode) {
                if (!in_array($named['shortcode'], $shortcodesInProject, true)) {
                    $strays[] = $issue;
                }
                continue;
            }
            if (!in_array($named['step'], $knownSteps, true)) {
                $strays[] = $issue;
                continue;
            }
            $notes[$named['step']][] = $issue;
        }

        $lines = [];
        foreach ($stepRows as $row) {
            $slug = RowValue::string($row, 'slug');
            $issues = $notes[$slug] ?? [];
            unset($notes[$slug]);
            $nah = array_any($issues, static fn (NoteIssue $i): bool => $i->statusSlug === SiteStanding::NAH_SLUG);

            $answer = HostChecks::answer(
                RowValue::string($row, 'check_name'),
                RowValue::stringOrNull($row, 'check_param'),
                $facts,
            );
            if ($answer === null) {
                $state = $openStates[$slug] ?? null;
                $answer = $state === null
                    ? new CheckResult(Outcome::Unknown, self::NOT_CHECKED)
                    : new CheckResult(Outcome::tryFrom($state['outcome']) ?? Outcome::Unknown, $state['detail']);
            }

            $lines[] = new StepLine(
                $slug,
                RowValue::string($row, 'track_name'),
                RowValue::string($row, 'title'),
                RowValue::enum($row, 'whose_move', WhoseMove::class),
                $nah || $answer->outcome === Outcome::Pass,
                $answer->detail,
                $nah,
                $issues,
                RowValue::string($row, 'explanation'),
                RowValue::string($row, 'how_to'),
                RowValue::string($row, 'check_name'),
                RowValue::stringOrNull($row, 'check_param'),
            );
        }

        $outcomes = array_map(static fn (array $state): string => $state['outcome'], $openStates);

        return new self(SiteStanding::of(HostChecks::reachable($facts), $lines), $lines, $strays, $notes, $outcomes);
    }
}
