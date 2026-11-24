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

    private readonly \DateTimeImmutable $now;

    public function __construct(
        private readonly \PDO $di_pdo,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->now = $now ?? new \DateTimeImmutable();
    }

    /**
     * May this signup go ahead? register.php asks this once, before it
     * creates the account.
     *
     * The signup is written down first and counted second, so a burst of
     * parallel signups cannot all read "under the limit" before any of them
     * has been recorded. Its own row is in the count, so "over" means past
     * the limit. A refused signup takes its own row back out, so being
     * refused does not lengthen the wait. An admitted one keeps its row
     * whether or not the account is then created.
     */
    public function admit(string $ip_address): bool
    {
        $since = $this->format($this->now->modify('-' . self::WINDOW_SECONDS . ' seconds'));
        $ip_bin = IPBin::ipToBinary($ip_address);

        $stmt = $this->di_pdo->prepare(
            "INSERT INTO `login_attempts` (`username`, `ip_address`, `attempted_at`) VALUES (?, ?, ?)"
        );
        $stmt->execute([self::SIGNUP_MARKER, $ip_bin === '' ? null : $ip_bin, $this->format($this->now)]);
        $signup_id = (int) $this->di_pdo->lastInsertId();

        $stmt = $this->di_pdo->prepare(
            "SELECT COUNT(*) FROM `login_attempts` WHERE `username` = ? AND `attempted_at` > ?"
        );
        $stmt->execute([self::SIGNUP_MARKER, $since]);
        $over_the_limit = (int) $stmt->fetchColumn() > self::MAX_TOTAL;

        if (!$over_the_limit && $ip_bin !== '') {
            $stmt = $this->di_pdo->prepare(
                "SELECT COUNT(*) FROM `login_attempts` WHERE `username` = ? AND `ip_address` = ? AND `attempted_at` > ?"
            );
            $stmt->execute([self::SIGNUP_MARKER, $ip_bin, $since]);
            $over_the_limit = (int) $stmt->fetchColumn() > self::MAX_PER_IP;
        }

        if ($over_the_limit) {
            $stmt = $this->di_pdo->prepare("DELETE FROM `login_attempts` WHERE `login_attempt_id` = ?");
            $stmt->execute([$signup_id]);
            return false;
        }
        return true;
    }

    private function format(\DateTimeImmutable $when): string
    {
        return $when->format('Y-m-d H:i:s');
    }
}
