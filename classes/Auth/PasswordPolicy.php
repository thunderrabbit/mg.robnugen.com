<?php

declare(strict_types=1);

namespace Auth;

/**
 * The one password rule: at least 12 characters (Rob, 2026-10-21, mg #740).
 * No character-class rules: length is what makes a password hard to guess,
 * and composition rules mostly produce "Password1!". Applies when a password
 * is set; existing shorter passwords keep working until they are changed.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /**
     * Why a new password is refused, for the form; '' when it is fine.
     */
    public static function problem(string $password): string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Passwords need at least ' . self::MIN_LENGTH . ' characters.';
        }
        return '';
    }
}
