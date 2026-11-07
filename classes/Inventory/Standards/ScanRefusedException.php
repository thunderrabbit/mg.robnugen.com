<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * A well-formed scan that must not be imported: it is not newer than the last
 * one stored, or it names a site or a step mg does not have.
 */
final class ScanRefusedException extends \RuntimeException
{
}
