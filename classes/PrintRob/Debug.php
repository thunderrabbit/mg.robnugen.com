<?php

declare(strict_types=1);

namespace PrintRob;

/**
 * print_r, wrapped in <pre>, and by default the end of the request.
 *
 * Rob wrote it as his variant of print_r, and Alfonzo agreed it was useful.
 * It first appears in andbeyond-backend (634da1d94, "More prep for
 * development.", 22 Dec 2011) and came to new-DH-user-site from there
 * as the loose function print_rob().
 */
final class Debug
{
    /**
     * @param mixed $object The thing to show. An object with toArray(),
     *        such as a ResultSet, is shown as that array.
     * @param bool  $exit   Stop the request after printing (default: true).
     */
    public static function printRob(mixed $object, bool $exit = true): void
    {
        echo "<pre>";
        if (is_object($object) && method_exists($object, "toArray")) {
            echo "ResultSet => " . print_r($object->toArray(), true);
        } else {
            print_r($object);
        }
        echo "</pre>";
        if ($exit) {
            exit;
        }
    }
}
