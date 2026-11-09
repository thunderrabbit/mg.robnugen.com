<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The rule for a site's mark. In this order:
 *
 * - nobody can reach it (no domain, or no proven ssh login): the face,
 *   whatever else is true of it;
 * - every proven step done: the checkmark;
 * - otherwise the info circle. It says "waiting on you" when an agent can
 *   do nothing until Rob moves; else how many steps are left, and how many
 *   of them Rob could do now.
 *
 * An open step waits on every open step before it in its own track, and on
 * every open Groundwork step. Across other tracks order does not matter.
 *
 * None of these is a warning. Every site here is working.
 */
final class SiteStanding
{
    public const string NAH_SLUG = 'nah';

    private const string GROUNDWORK = 'Groundwork';

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
        $agentFree = 0;
        $robFree = 0;
        foreach ($open as $i => $line) {
            $other = $line->whoseMove === WhoseMove::Rob ? WhoseMove::Agent : WhoseMove::Rob;
            $held = array_any(
                array_slice($open, 0, $i),
                static fn (StepLine $before): bool => $before->whoseMove === $other
                    && ($before->trackName === $line->trackName || $before->trackName === self::GROUNDWORK),
            );
            if (!$held) {
                $line->whoseMove === WhoseMove::Rob ? $robFree++ : $agentFree++;
            }
        }
        if ($agentFree === 0) {
            return new Standing(Mark::Info, 'waiting on you');
        }
        return new Standing(
            Mark::Info,
            count($open) === 1 ? '1 step to go' : count($open) . ' steps to go',
            $robFree,
        );
    }
}
