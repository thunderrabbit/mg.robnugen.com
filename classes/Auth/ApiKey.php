<?php
/**
 * Manages API key authentication for external agent access.
 * Follows the same constructor pattern as Auth\IsLoggedIn.
 */
namespace Auth;

class ApiKey
{
    public function __construct(
        private \PDO $di_pdo,
    ) {}

    /**
     * Validates an API key and returns the associated user_id, or null if invalid/inactive.
     * Also updates last_used timestamp on success.
     */
    public function validateKey(string $raw_key): ?int
    {
        $stmt = $this->di_pdo->prepare(
            "SELECT user_id FROM api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$raw_key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $update = $this->di_pdo->prepare(
            "UPDATE api_keys SET last_used = NOW() WHERE api_key = ?"
        );
        $update->execute([$raw_key]);

        return (int) $row['user_id'];
    }

    /**
     * Generates a new API key for the user, stores it, and returns the raw key.
     * Key format: 'sk_' prefix + 61 random chars = 64 chars total (fits CHAR(64)).
     */
    public function generateKey(int $user_id, string $label = ''): string
    {
        $raw_key = 'sk_' . \Utilities::randomString(61);

        $stmt = $this->di_pdo->prepare(
            "INSERT INTO api_keys (user_id, api_key, label) VALUES (?, ?, ?)"
        );
        $stmt->execute([$user_id, $raw_key, $label]);

        return $raw_key;
    }

    /**
     * Revokes a key by key_id. Verifies ownership before deactivating.
     * Returns true if a row was actually updated.
     */
    public function revokeKey(int $key_id, int $user_id): bool
    {
        $stmt = $this->di_pdo->prepare(
            "UPDATE api_keys SET is_active = 0 WHERE key_id = ? AND user_id = ?"
        );
        $stmt->execute([$key_id, $user_id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Returns all active keys for a user: key_id, label, created_at, last_used.
     * Does NOT return the raw api_key value — only show it once at generation time.
     */
    public function getKeysForUser(int $user_id): array
    {
        $stmt = $this->di_pdo->prepare(
            "SELECT key_id, label, created_at, last_used
             FROM api_keys
             WHERE user_id = ? AND is_active = 1
             ORDER BY created_at DESC"
        );
        $stmt->execute([$user_id]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
