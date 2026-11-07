<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * A step file or a scan document that is not shaped the way the standards
 * page needs. The message names the file or field, for whoever wrote it.
 */
final class StandardsFormatException extends \RuntimeException
{
}
