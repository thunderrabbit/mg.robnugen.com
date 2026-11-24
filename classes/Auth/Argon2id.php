<?php

declare(strict_types=1);

namespace Auth;

/**
 * Can the PHP serving this site hash passwords with argon2id?
 *
 * Asked on /admin/ because that runs under the web server's PHP. The shell's
 * `php` over ssh is a different build and proves nothing about this one.
 *
 * It matters for Auth\PasswordPolicy: bcrypt reads only 72 bytes, argon2id
 * has no such limit, and mg can change over only once its served PHP has it.
 * One bcrypt hash at today's default cost is timed beside it, so the two
 * costs can be compared before logins move to argon2id.
 */
final class Argon2id
{
    private const string ALGORITHM = 'argon2id';
    private const string TRIAL = 'a trial, not a password';

    /**
     * @param list<string>|null $algorithms password_algos() by default.
     */
    public static function isListed(?array $algorithms = null): bool
    {
        return in_array(self::ALGORITHM, $algorithms ?? password_algos(), true);
    }

    /**
     * Makes one real hash, because being listed is not the same as working:
     * the default cost wants 64 MB, which a shared host may not hand over.
     *
     * @return int|null Milliseconds the hash took, or null when it failed.
     */
    public static function trialMilliseconds(): ?int
    {
        return self::timeOneHash(self::ALGORITHM);
    }

    /**
     * Makes one bcrypt hash at PHP's default cost, for comparison.
     *
     * @return int|null Milliseconds the hash took, or null when it failed.
     */
    public static function bcryptMilliseconds(): ?int
    {
        return self::timeOneHash(PASSWORD_BCRYPT);
    }

    /**
     * One line for the admin dashboard.
     *
     * @param int|null $trial_ms trialMilliseconds()'s answer; ignored when not listed.
     * @param int|null $bcrypt_ms bcryptMilliseconds()'s answer.
     * @param int $bcrypt_cost The cost that bcrypt hash was made at.
     */
    public static function summary(bool $is_listed, ?int $trial_ms, ?int $bcrypt_ms, int $bcrypt_cost): string
    {
        if (!$is_listed) {
            $argon2id = 'bcrypt only; this PHP has no argon2id';
        } elseif ($trial_ms === null) {
            $argon2id = 'argon2id is listed, but a test hash failed';
        } else {
            $argon2id = "argon2id available (a test hash took {$trial_ms} ms)";
        }
        $bcrypt = $bcrypt_ms === null
            ? "a bcrypt test hash at cost {$bcrypt_cost} failed"
            : "bcrypt at cost {$bcrypt_cost} took {$bcrypt_ms} ms";

        return "{$argon2id}; {$bcrypt}";
    }

    /**
     * summary() for the PHP that is running.
     */
    public static function report(): string
    {
        $is_listed = self::isListed();
        return self::summary(
            $is_listed,
            $is_listed ? self::trialMilliseconds() : null,
            self::bcryptMilliseconds(),
            PASSWORD_BCRYPT_DEFAULT_COST,
        );
    }

    /**
     * @return int|null Milliseconds the hash took, or null when it failed or does not verify.
     */
    private static function timeOneHash(string $algorithm): ?int
    {
        $started = hrtime(true);
        try {
            $hash = password_hash(self::TRIAL, $algorithm);
        } catch (\Throwable) {
            return null;
        }
        // A wall-clock reading for a person to look at; no test can say what
        // it should be.
        // @infection-ignore-all
        $milliseconds = intdiv(hrtime(true) - $started, 1_000_000);

        return password_verify(self::TRIAL, $hash) ? $milliseconds : null;
    }
}
