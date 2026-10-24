<?php

declare(strict_types=1);

namespace Inventory;

/**
 * A well-formed sweep that must not be imported now: it is already in, or it
 * is older than the newest one stored.
 */
final class SweepRefusedException extends \RuntimeException
{
}
