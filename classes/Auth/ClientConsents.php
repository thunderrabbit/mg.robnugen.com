<?php

declare(strict_types=1);

namespace Auth;

/**
 * The oauth_client_consents table: which actor a user already approved for
 * a client and redirect address, so the authorize page need not ask again.
 * ConnectedApps::revokeAll() clears them.
 */
class ClientConsents
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * The actor the user approved for this client and redirect address, or
     * null when they have approved none.
     */
    public function rememberedAiuOf(int $user_id, string $client_id, string $redirect_uri): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT aiu_id FROM oauth_client_consents
             WHERE user_id = ? AND client_id = ? AND redirect_uri = ?
             LIMIT 1"
        );
        $stmt->execute([$user_id, $client_id, $redirect_uri]);
        $remembered_aiu = $stmt->fetchColumn();
        return $remembered_aiu === false ? null : \Field::toInt($remembered_aiu);
    }

    /**
     * Remembers the user's choice of actor, replacing an earlier one for the
     * same client and redirect address.
     */
    public function remember(int $user_id, int $aiu_id, string $client_id, string $redirect_uri): void
    {
        $this->pdo->prepare(
            "INSERT INTO oauth_client_consents (user_id, aiu_id, client_id, redirect_uri)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE aiu_id = VALUES(aiu_id), created_at_utc = NOW()"
        )->execute([$user_id, $aiu_id, $client_id, $redirect_uri]);
    }
}
