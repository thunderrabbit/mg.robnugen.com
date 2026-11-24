<?php

declare(strict_types=1);

namespace Auth;

/**
 * The mcp_tokens table: OAuth access tokens and their refresh tokens, each
 * bound to one actor of one account. Only SHA-256 hashes are stored; this
 * class takes and compares hashes and never sees a raw token.
 *
 * ConnectedApps lists and revokes these for the settings page, and
 * BearerToken resolves one for the v1 API.
 */
class McpTokens
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * The user_id and aiu_id a live access token acts as, or null when the
     * hash is unknown or the token has expired.
     *
     * @return array<string, mixed>|null
     */
    public function userAndActorOf(string $token_hash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT user_id, aiu_id FROM mcp_tokens
             WHERE token_hash = ? AND expires_at_utc > NOW()
             LIMIT 1"
        );
        $stmt->execute([$token_hash]);
        return self::row($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * The whole row a live refresh token belongs to, or null when the hash
     * is unknown, expired or already rotated away.
     *
     * @return array<string, mixed>|null
     */
    public function getByRefreshHash(string $refresh_hash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM mcp_tokens
             WHERE refresh_token_hash = ? AND refresh_expires_at_utc > NOW()
             LIMIT 1"
        );
        $stmt->execute([$refresh_hash]);
        return self::row($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * Replaces both tokens of a row. Matching the old refresh hash makes the
     * swap atomic: of two requests racing with the same refresh token, only
     * the first rotates, and the second gets false.
     */
    public function rotate(
        int $token_id,
        string $old_refresh_hash,
        string $token_hash,
        int $expires_in,
        ?string $refresh_hash,
        int $refresh_ttl_days
    ): bool {
        $stmt = $this->pdo->prepare(
            "UPDATE mcp_tokens
             SET token_hash = ?, expires_at_utc = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 refresh_token_hash = ?, refresh_expires_at_utc = DATE_ADD(NOW(), INTERVAL ? DAY)
             WHERE token_id = ? AND refresh_token_hash = ?"
        );
        $stmt->execute([$token_hash, $expires_in, $refresh_hash, $refresh_ttl_days, $token_id, $old_refresh_hash]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Stores a new access token, with its refresh token when it has one.
     * With no refresh token both `$refresh_hash` and `$refresh_ttl_days` are
     * null.
     */
    public function addToken(
        int $aiu_id,
        int $user_id,
        string $token_hash,
        int $expires_in,
        ?string $refresh_hash,
        ?int $refresh_ttl_days
    ): void {
        $this->pdo->prepare(
            "INSERT INTO mcp_tokens (aiu_id, user_id, token_hash, expires_at_utc, refresh_token_hash, refresh_expires_at_utc)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
        )->execute([$aiu_id, $user_id, $token_hash, $expires_in, $refresh_hash, $refresh_ttl_days]);
    }

    /**
     * Deletes the tokens that have expired and can no longer be refreshed.
     */
    public function deleteExpired(): void
    {
        $this->pdo->prepare("DELETE FROM mcp_tokens WHERE expires_at_utc < NOW() AND (refresh_expires_at_utc IS NULL OR refresh_expires_at_utc < NOW())")->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function row(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        /** @var array<string, mixed> $row */
        return $row;
    }
}
