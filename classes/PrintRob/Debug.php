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

    /**
     * Append one timestamped line to ~/rob.log (the temp directory's
     * rob.log when not under /home/<user>/). Never stops the request.
     *
     * @param mixed  $message A string is written as is; anything else
     *        through print_r.
     * @param string $label   Shown in square brackets before the message.
     */
    public static function printRobLog(mixed $message, string $label = ''): void
    {
        $prefix = $label ? "[$label] " : '';
        $line = date('Y-m-d H:i:s') . " {$prefix}" . (is_string($message) ? $message : print_r($message, true)) . "\n";
        preg_match('#^(/home/[^/]+)#', __DIR__, $m);
        $logfile = !empty($m[1]) ? $m[1] . '/rob.log' : sys_get_temp_dir() . '/rob.log';
        @file_put_contents($logfile, $line, FILE_APPEND);
    }
}
