<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The model a step names for the agent that does it: the names the Agent
 * tool's `model` parameter takes. A step file says it with a `model:` line,
 * or its track's _track.md says it for every step in the track.
 */
enum StepModel: string
{
    case Haiku = 'haiku';
    case Sonnet = 'sonnet';
    case Opus = 'opus';
    case Fable = 'fable';
}
