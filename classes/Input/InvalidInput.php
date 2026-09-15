<?php

declare(strict_types=1);

namespace Input;

/**
 * A request field was missing or malformed. The message is written for the
 * caller ("title is required", "priority must be 'low', 'normal', or 'high'"),
 * so each surface can pass it straight through as a 400, a JSON-RPC error or a
 * form message.
 */
final class InvalidInput extends \InvalidArgumentException
{
}
