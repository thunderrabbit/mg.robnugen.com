<?php

declare(strict_types=1);

namespace Database;

/**
 * Persistence for remember-me cookies (the `cookies` table).
 *
 * Rows hold the SHA-256 of the token, never the token: callers hash before
 * they get here (see Auth\IsLoggedIn). Every lookup honours `expires_at`, so
 * a token captured from a browser or a backup dies with the row's lifetime
 * instead of living as long as the browser says. Issuing a cookie also purges
 * expired rows, which keeps the table bounded without a cron entry.
 *
 * Timestamps are computed in PHP and the SQL kept portable so the Unit suite
 * can drive this on an in-memory SQLite. They are written in UTC: the PDO
 * session runs in UTC (Database\Base), so a local-time string would be read
 * back hours off. created_at is MySQL's own CURRENT_TIMESTAMP, also UTC there,
 * so the three columns agree and the devices page can say how long ago.
 */
class CookieRepository
{
    /** MySQL: unknown column. */
    private const SQLSTATE_NO_COLUMN = '42S22';

    /** VARCHAR width of `cookies`.`user_agent`. */
    private const USER_AGENT_WIDTH = 255;

    /** How stale `last_access` may get before touch() writes it again. */
    private const TOUCH_EVERY_SECONDS = 3600;

    /**
     * What listForUser() can SELECT for user_agent and device_name, newest
     * schema first. A deploy can land before a migration does: without 43
     * there is no device_name column, and without 37 no user_agent either.
     *
     * @var list<array{string, string}>
     */
    private const LIST_COLUMNS = [
        ['`user_agent`', '`device_name`'],
        ['`user_agent`', 'NULL'],
        ["''", 'NULL'],
    ];

    private \DateTimeImmutable $now;

    public function __construct(
        private \PDO $di_pdo,
        ?\DateTimeImmutable $now = null,
    ) {
        // UTC before any arithmetic: "+30 days" in America/Los_Angeles is an
        // hour short or long across a DST change, and the column is UTC anyway.
        $this->now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
    }

    public function issue(
        int $user_id,
        string $cookie_hash,
        string $user_agent_md5,
        int $lifetime_seconds,
        string $user_agent = '',
    ): void {
        $params = [
            $user_id,
            $cookie_hash,
            $this->format($this->now),
            $this->format($this->now->modify("+{$lifetime_seconds} seconds")),
            $user_agent_md5,
        ];
        try {
            $stmt = $this->di_pdo->prepare(
                "INSERT INTO `cookies` (`user_id`, `cookie`, `last_access`, `expires_at`, `user_agent_md5`, `user_agent`)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([...$params, substr($user_agent, 0, self::USER_AGENT_WIDTH)]);
        } catch (\PDOException $e) {
            // A deploy can land before migration 37 does. Login must keep
            // working; the devices page just cannot name the browser yet.
            if (!self::isMissingColumn($e)) {
                throw $e;
            }
            $stmt = $this->di_pdo->prepare(
                "INSERT INTO `cookies` (`user_id`, `cookie`, `last_access`, `expires_at`, `user_agent_md5`)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute($params);
        }
        $this->purgeExpired();
    }

    /**
     * The user a presented cookie belongs to, or 0 when it is unknown, was
     * issued to a different browser, or has expired. The IP is not checked
     * (migration 06_remove_ip_auth): phones and laptops change networks.
     */
    public function findUserId(string $cookie_hash, string $user_agent_md5): int
    {
        $stmt = $this->di_pdo->prepare(
            "SELECT `user_id` FROM `cookies`
             WHERE `cookie` = ? AND `user_agent_md5` = ? AND `expires_at` > ?
             LIMIT 1"
        );
        $stmt->execute([$cookie_hash, $user_agent_md5, $this->format($this->now)]);
        $user_id = $stmt->fetchColumn();
        return is_numeric($user_id) ? (int) $user_id : 0;
    }

    /**
     * Why findUserId() refused a cookie, for the auth log: 'expired',
     * 'user agent mismatch', or 'not found'. The user_id is 0 when not found.
     *
     * @return array{reason: string, user_id: int}
     */
    public function whyRefused(string $cookie_hash, string $user_agent_md5): array
    {
        $stmt = $this->di_pdo->prepare(
            "SELECT `user_id`, `user_agent_md5`, `expires_at` FROM `cookies` WHERE `cookie` = ? LIMIT 1"
        );
        $stmt->execute([$cookie_hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['reason' => 'not found', 'user_id' => 0];
        }
        $user_id = is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0;
        if (($row['expires_at'] ?? '') <= $this->format($this->now)) {
            return ['reason' => 'expired', 'user_id' => $user_id];
        }
        if (($row['user_agent_md5'] ?? '') !== $user_agent_md5) {
            return ['reason' => 'user agent mismatch', 'user_id' => $user_id];
        }
        return ['reason' => 'not found', 'user_id' => $user_id];
    }

    public function revoke(string $cookie_hash): void
    {
        $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `cookie` = ?");
        $stmt->execute([$cookie_hash]);
    }

    /**
     * Sign a user out everywhere. Pass the hash of the cookie in hand to keep
     * the current browser logged in ("everywhere else"), which is what a
     * password change wants. Returns the number of sessions revoked.
     */
    public function revokeAllForUser(int $user_id, ?string $keep_cookie_hash = null): int
    {
        if ($keep_cookie_hash === null) {
            $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `user_id` = ?");
            $stmt->execute([$user_id]);
        } else {
            $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `user_id` = ? AND `cookie` <> ?");
            $stmt->execute([$user_id, $keep_cookie_hash]);
        }
        return $stmt->rowCount();
    }

    /**
     * Revoke one of a user's devices by row id. Scoped to the user, so a
     * posted cookie_id belonging to someone else revokes nothing. True when a
     * row went away.
     */
    public function revokeDevice(int $user_id, int $cookie_id): bool
    {
        $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `cookie_id` = ? AND `user_id` = ?");
        $stmt->execute([$cookie_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Name the device whose cookie is in hand, or null to take its name away.
     * Scoped to the presented cookie on purpose, not a posted cookie_id: a
     * session stolen on one device may rename itself and nothing else. The
     * user_id is belt and braces. True when the row is live and theirs.
     *
     * last_access is ON UPDATE CURRENT_TIMESTAMP in MySQL, so the SET keeps
     * it as it was: naming a device is not using it.
     */
    public function rename(int $user_id, string $cookie_hash, ?string $name): bool
    {
        $owned = [$cookie_hash, $user_id, $this->format($this->now)];
        $stmt = $this->di_pdo->prepare(
            "UPDATE `cookies` SET `device_name` = ?, `last_access` = `last_access`
             WHERE `cookie` = ? AND `user_id` = ? AND `expires_at` > ?"
        );
        $stmt->execute([$name, ...$owned]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        // MySQL's rowCount is rows changed, not rows matched, so renaming a
        // device to the name it already has reads 0. Ask whether it is theirs.
        $stmt = $this->di_pdo->prepare(
            "SELECT 1 FROM `cookies` WHERE `cookie` = ? AND `user_id` = ? AND `expires_at` > ?"
        );
        $stmt->execute($owned);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Record that a cookie was just used, so the devices page can say when.
     * Runs on every logged-in request, so it writes at most once an hour per
     * cookie; the WHERE clause makes the other requests a no-op read.
     */
    public function touch(string $cookie_hash): void
    {
        $stmt = $this->di_pdo->prepare(
            "UPDATE `cookies` SET `last_access` = ?
             WHERE `cookie` = ? AND (`last_access` IS NULL OR `last_access` < ?)"
        );
        $stmt->execute([
            $this->format($this->now),
            $cookie_hash,
            $this->format($this->now->modify('-' . self::TOUCH_EVERY_SECONDS . ' seconds')),
        ]);
    }

    /**
     * A user's live devices, most recently used first. The one whose hash is
     * $current_cookie_hash (the browser asking) is flagged is_current.
     *
     * @return list<\Auth\Device>
     */
    public function listForUser(int $user_id, ?string $current_cookie_hash): array
    {
        $params = [$current_cookie_hash ?? '', $user_id, $this->format($this->now)];
        $sql = "SELECT `cookie_id`, %s AS `user_agent`, %s AS `device_name`,
                       `created_at`, `last_access`, `expires_at`,
                       (`cookie` = ?) AS `is_current`
                FROM `cookies`
                WHERE `user_id` = ? AND `expires_at` > ?
                ORDER BY `last_access` DESC, `cookie_id` DESC";

        $devices = [];
        foreach ($this->selectDevices($sql, $params) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = self::str($row['device_name'] ?? '');
            $devices[] = new \Auth\Device(
                cookie_id: self::int($row['cookie_id'] ?? 0),
                user_agent: self::str($row['user_agent'] ?? ''),
                created_at: self::str($row['created_at'] ?? ''),
                last_access: self::str($row['last_access'] ?? ''),
                expires_at: self::str($row['expires_at'] ?? ''),
                is_current: self::int($row['is_current'] ?? 0) === 1,
                device_name: $name === '' ? null : $name,
            );
        }
        return $devices;
    }

    /**
     * Run the devices query with the newest column set the table has (see
     * LIST_COLUMNS). Any error other than a missing column is the caller's.
     *
     * @param list<mixed> $params
     * @return list<mixed>
     */
    private function selectDevices(string $sql, array $params): array
    {
        $oldest = array_key_last(self::LIST_COLUMNS);
        foreach (self::LIST_COLUMNS as $i => [$user_agent, $device_name]) {
            try {
                $stmt = $this->di_pdo->prepare(sprintf($sql, $user_agent, $device_name));
                $stmt->execute($params);
                return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
            } catch (\PDOException $e) {
                if ($i === $oldest || !self::isMissingColumn($e)) {
                    throw $e;
                }
            }
        }
        return [];
    }

    public function purgeExpired(): void
    {
        $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `expires_at` <= ?");
        $stmt->execute([$this->format($this->now)]);
    }

    private function format(\DateTimeImmutable $when): string
    {
        return $when->format('Y-m-d H:i:s');
    }

    /** A PDO column value as a string; NULL and anything odd is ''. */
    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** A PDO column value as an int (drivers differ on int vs numeric string). */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Whether the statement named a column the table does not have (yet). */
    private static function isMissingColumn(\PDOException $e): bool
    {
        // MySQL reports SQLSTATE 42S22 (unknown column). SQLite says "has no
        // column named" on INSERT and "no such column" on SELECT, with HY000.
        return $e->getCode() === self::SQLSTATE_NO_COLUMN
            || str_contains($e->getMessage(), 'no column named')
            || str_contains($e->getMessage(), 'no such column');
    }
}
