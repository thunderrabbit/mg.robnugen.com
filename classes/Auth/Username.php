<?php

declare(strict_types=1);

namespace Auth;

/**
 * What a username may be: 3 to 64 printable ASCII characters, no spaces.
 *
 * users.username uses MySQL's accent-insensitive collation, so "ádmin" finds
 * the account "admin". The login throttle counts usernames exactly, and gave
 * every accented spelling its own fresh bucket. Rob decided (mg #739) that
 * usernames are plain ASCII; every account on mg already was. Letters,
 * digits and punctuation such as _ . - @ are all fine.
 */
class Username
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 64;

    public static function isValid(string $username): bool
    {
        return preg_match('/^[\x21-\x7E]{' . self::MIN_LENGTH . ',' . self::MAX_LENGTH . '}$/D', $username) === 1;
    }

    /**
     * Why a username is refused, for the registration form; '' when it is fine.
     */
    public static function problem(string $username): string
    {
        if (self::isValid($username)) {
            return '';
        }
        return 'Usernames are ' . self::MIN_LENGTH . ' to ' . self::MAX_LENGTH
            . ' characters of plain ASCII (letters, digits and punctuation such as _ . - @), with no spaces.';
    }
}
