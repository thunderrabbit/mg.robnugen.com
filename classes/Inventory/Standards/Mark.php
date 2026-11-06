<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The three marks a site can carry on the standards page, and no others: a
 * green checkmark, an info circle, or a crying-laughing face for a site
 * nobody can reach yet. Nothing here is a warning; every site is working.
 */
enum Mark: string
{
    case Check = 'check';
    case Info = 'info';
    case Face = 'face';
}
