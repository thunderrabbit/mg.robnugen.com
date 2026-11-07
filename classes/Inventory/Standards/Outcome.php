<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * What a check said about one step on one site. Unknown means the check
 * could not tell, which is not a failure of the site.
 */
enum Outcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Unknown = 'unknown';
}
