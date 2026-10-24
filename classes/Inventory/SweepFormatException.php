<?php

declare(strict_types=1);

namespace Inventory;

/**
 * A sweep that cannot be trusted: a field has the wrong shape, two files
 * disagree, or a field looks like a secret. Nothing is written when it is
 * thrown, so a careless future sweep fails loudly.
 */
final class SweepFormatException extends \RuntimeException
{
}
