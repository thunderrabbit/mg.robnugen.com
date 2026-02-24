<?php
/**
 * Manages API key authentication for external agent access.
 * Follows the same constructor pattern as Auth\IsLoggedIn.
 */
namespace Auth;

class ApiKey
{
    private ?int $last_key_id = null;

    public function __construct(
        private \PDO $di_pdo,
    ) {}

    /**
     * Validates an API key and returns the associated user_id, or null if invalid/inactive.
     * Also updates last_used timestamp on success and stores key_id for getLastKeyId().
     */
    public function validateKey(string $raw_key): ?int
    {
        $key_hash = hash('sha256', $raw_key);

        $stmt = $this->di_pdo->prepare(
            "SELECT key_id, user_id FROM api_keys WHERE api_key_hash = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$key_hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $this->last_key_id = (int) $row['key_id'];

        $update = $this->di_pdo->prepare(
            "UPDATE api_keys SET last_used = NOW() WHERE key_id = ?"
        );
        $update->execute([$this->last_key_id]);

        return (int) $row['user_id'];
    }

    /**
     * Returns the key_id from the most recent successful validateKey() call.
     * Needed for usage logging without a second DB query.
     */
    public function getLastKeyId(): ?int
    {
        return $this->last_key_id;
    }

    /**
     * Generates a new API key for the user, stores a SHA-256 hash, and returns the raw key.
     * The raw key is shown to the user once and never stored — only the hash is kept.
     * Key format: 'sk_' prefix + 61 random chars = 64 chars total.
     */
    public function generateKey(int $user_id, string $label = ''): string
    {
        $raw_key  = 'sk_' . \Utilities::randomString(61);
        $key_hash = hash('sha256', $raw_key);

        $stmt = $this->di_pdo->prepare(
            "INSERT INTO api_keys (user_id, api_key_hash, label) VALUES (?, ?, ?)"
        );
        $stmt->execute([$user_id, $key_hash, $label]);

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
