<?php

declare(strict_types=1);

namespace Input;

/**
 * Marks a request field that was not sent at all, as opposed to one sent as
 * null. PATCH-style commands type a field as int|null|Absent so "leave it
 * alone" and "clear it" can't be confused.
 */
enum Absent
{
    case Field;
}
