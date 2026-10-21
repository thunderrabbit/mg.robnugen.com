<?php

declare(strict_types=1);

namespace Auth;

/**
 * OAuth connections to mg's MCP server (mcp_tokens), and the remembered
 * consents that let a client get new codes without asking again.
 *
 * Remember-me cookies are revoked by CookieRepository; these are a separate
 * credential that survived logout and password changes, so a leaked refresh
 * token (400-day sliding life) stayed valid. This class lets a password
 * change revoke them and the settings page list and revoke them one by one.
 */
class ConnectedApps
{
    public function __construct(private \PDO $di_pdo)
    {
    }

    /**
     * @return list<array{token_id: int, agent_name: string, created_at_utc: string, refresh_expires_at_utc: string}>
     */
    public function listForUser(int $user_id): array
    {
        $stmt = $this->di_pdo->prepare(
            "SELECT t.token_id, a.name AS agent_name, t.created_at_utc,
                    COALESCE(t.refresh_expires_at_utc, t.expires_at_utc) AS refresh_expires_at_utc
             FROM mcp_tokens t
             JOIN agent_inbox_user a ON a.aiu_id = t.aiu_id AND a.user_id = t.user_id
             WHERE t.user_id = ?
             ORDER BY t.created_at_utc DESC"
        );
        $stmt->execute([$user_id]);
        $apps = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $apps[] = [
                'token_id' => \Field::int($row, 'token_id'),
                'agent_name' => \Field::string($row, 'agent_name'),
                'created_at_utc' => \Field::string($row, 'created_at_utc'),
                'refresh_expires_at_utc' => \Field::string($row, 'refresh_expires_at_utc'),
            ];
        }
        return $apps;
    }

    /**
     * Revoke one connection. Scoped to the user, so another user's token_id
     * revokes nothing. True when a connection went away.
     */
    public function revoke(int $user_id, int $token_id): bool
    {
        $stmt = $this->di_pdo->prepare("DELETE FROM mcp_tokens WHERE token_id = ? AND user_id = ?");
        $stmt->execute([$token_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke every connection and remembered consent, so each client has to
     * be approved again. Returns how many connections were revoked.
     */
    public function revokeAll(int $user_id): int
    {
        $stmt = $this->di_pdo->prepare("DELETE FROM mcp_tokens WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $this->di_pdo->prepare("DELETE FROM oauth_client_consents WHERE user_id = ?")->execute([$user_id]);
        return $stmt->rowCount();
    }
}
