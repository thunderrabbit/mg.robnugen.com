<?php

declare(strict_types=1);

namespace Issues;

/**
 * The change is valid but the issue's current state forbids it, such as moving
 * to a terminal status while timers are still running. The API answers 409.
 */
final class ConflictException extends \RuntimeException
{
}
