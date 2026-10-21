<?php

declare(strict_types=1);

namespace Auth;

/**
 * Limits account creation: registration is open (Rob, mg #740) and every
 * account gets trial API credits, so a script must not mint them in bulk.
 *
 * Signups are counted as rows in login_attempts under SIGNUP_MARKER. It holds
 * a space, which Auth\Username never allows, so it cannot collide with a real
 * account's login failures. The window matches LoginThrottle's, because
 * LoginThrottle purges every row older than its window.
 *
 * SQL is portable (timestamps from PHP) so the Unit suite runs on SQLite.
 */
class SignupThrottle
{
    public const SIGNUP_MARKER = ' signup';
    public const MAX_PER_IP = 3;
    public const MAX_TOTAL = 10;
    public const WINDOW_SECONDS = LoginThrottle::WINDOW_SECONDS;

    private \DateTimeImmutable $now;

    public function __construct(
        private \PDO $di_pdo,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->now = $now ?? new \DateTimeImmutable();
    }

    public function isThrottled(string $ip_address): bool
    {
        $since = $this->format($this->now->modify('-' . self::WINDOW_SECONDS . ' seconds'));

        $stmt = $this->di_pdo->prepare(
            "SELECT COUNT(*) FROM `login_attempts` WHERE `username` = ? AND `attempted_at` > ?"
        );
        $stmt->execute([self::SIGNUP_MARKER, $since]);
        if ((int) $stmt->fetchColumn() >= self::MAX_TOTAL) {
            return true;
        }

        $ip_bin = IPBin::ipToBinary($ip_address);
        if ($ip_bin === '') {
            return false;
        }
        $stmt = $this->di_pdo->prepare(
            "SELECT COUNT(*) FROM `login_attempts` WHERE `username` = ? AND `ip_address` = ? AND `attempted_at` > ?"
        );
        $stmt->execute([self::SIGNUP_MARKER, $ip_bin, $since]);
        return (int) $stmt->fetchColumn() >= self::MAX_PER_IP;
    }

    public function recordSignup(string $ip_address): void
    {
        $ip_bin = IPBin::ipToBinary($ip_address);
        $stmt = $this->di_pdo->prepare(
            "INSERT INTO `login_attempts` (`username`, `ip_address`, `attempted_at`) VALUES (?, ?, ?)"
        );
        $stmt->execute([self::SIGNUP_MARKER, $ip_bin === '' ? null : $ip_bin, $this->format($this->now)]);
    }

    private function format(\DateTimeImmutable $when): string
    {
        return $when->format('Y-m-d H:i:s');
    }
}
