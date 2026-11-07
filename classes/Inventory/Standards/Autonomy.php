<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * How much of a site's work Rob wants to be alongside for, from now on. It is
 * his instruction to whoever works on the site; nothing in mg acts on it.
 */
enum Autonomy: string
{
    case Paired = 'paired';
    case Reviewed = 'reviewed';
    case Solo = 'solo';
}
