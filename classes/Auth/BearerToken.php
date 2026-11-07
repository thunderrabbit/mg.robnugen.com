<?php

declare(strict_types=1);

namespace Auth;

/**
 * OAuth access tokens (mcp_tokens, stored as sha256) presented as
 * "Authorization: Bearer ..." on /api/v1. The caller decides which actors
 * may use them (only todos_limited ones, mg #801).
 */
class BearerToken
{
    public function __construct(private readonly \PDO $di_pdo)
    {
    }

    /**
     * The raw token from the Authorization header, or null.
     *
     * DreamHost FastCGI may deliver the header as REDIRECT_HTTP_AUTHORIZATION.
     *
     * @param array<mixed> $server
     */
    public function fromHeaders(array $server): ?string
    {
        $header = \Field::string($server, 'HTTP_AUTHORIZATION', \Field::string($server, 'REDIRECT_HTTP_AUTHORIZATION'));
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * The live token row, only if its actor belongs to the token's account.
     *
     * @return array{token_id: int, user_id: int, aiu_id: int}|null
     */
    public function resolve(string $raw): ?array
    {
        $stmt = $this->di_pdo->prepare(
            "SELECT t.token_id, t.user_id, t.aiu_id
             FROM mcp_tokens t
             JOIN agent_inbox_user a ON a.aiu_id = t.aiu_id AND a.user_id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at_utc > ?
             LIMIT 1"
        );
        $stmt->execute([hash('sha256', $raw), gmdate('Y-m-d H:i:s')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'token_id' => \Field::int($row, 'token_id'),
            'user_id' => \Field::int($row, 'user_id'),
            'aiu_id' => \Field::int($row, 'aiu_id'),
        ];
    }
}
