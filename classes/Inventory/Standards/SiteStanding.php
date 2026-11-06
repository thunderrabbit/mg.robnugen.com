<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The rule for a site's mark. In this order:
 *
 * - nobody can reach it (no domain, or no proven ssh login): the face,
 *   whatever else is true of it;
 * - every proven step done: the checkmark;
 * - otherwise the info circle, saying whether Rob's move is what it waits on.
 *
 * None of these is a warning. Every site here is working.
 */
final class SiteStanding
{
    public const string NAH_SLUG = 'nah';

    /**
     * @param list<StepLine> $provenLines
     */
    public static function of(bool $reachable, array $provenLines): Standing
    {
        if (!$reachable) {
            return new Standing(Mark::Face, "can't reach it yet");
        }
        $open = array_values(array_filter($provenLines, static fn (StepLine $line): bool => !$line->done));
        if ($open === []) {
            return new Standing(Mark::Check, 'up to date');
        }
        foreach ($open as $line) {
            if ($line->whoseMove === WhoseMove::Rob) {
                return new Standing(Mark::Info, 'waiting on you');
            }
        }
        return new Standing(Mark::Info, count($open) === 1 ? '1 step to go' : count($open) . ' steps to go');
    }
}
