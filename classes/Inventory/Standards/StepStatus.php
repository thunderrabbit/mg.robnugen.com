<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Whether any site has done a step yet. An idea counts against no one; a
 * proven step counts for every site of its standard.
 */
enum StepStatus: string
{
    case Idea = 'idea';
    case Proven = 'proven';
}
