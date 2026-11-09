<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Who can do a step: only Rob (a panel change, a lost login, or a change
 * whose shape is a matter of his taste), or an agent on its own.
 */
enum WhoseMove: string
{
    case Rob = 'rob';
    case Agent = 'agent';
}
