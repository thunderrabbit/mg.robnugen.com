<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Who can do a step: only Rob (a panel change, a lost login), or an agent.
 */
enum WhoseMove: string
{
    case Rob = 'rob';
    case Agent = 'agent';
}
