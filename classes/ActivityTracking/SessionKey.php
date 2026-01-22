<?php

namespace ActivityTracking;

class SessionKey {
    private $pdo;

    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Generate YouTube-style session key
     * 11 chars = 64^11 = 73.7 quadrillion combinations
     *
     * @return string 11-character session key
     */
    public function generateSessionKey(): string {
        // Base64url encoding: a-z, A-Z, 0-9, _, -
        $chars = '_-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $key = '';
        for ($i = 0; $i < 11; $i++) {
            $key .= $chars[random_int(0, 63)]; // 64 chars total
        }
        return $key;
    }

    /**
     * Create session key for an activity record
     * Retries if collision occurs
     *
     * @param int $ak_id Activity session ID
     * @return string The generated session key
     */
    public function createSessionKey(int $ak_id): string {
        $maxRetries = 10;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $sessionKey = $this->generateSessionKey();

            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO activity_session_keys (session_key, ak_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$sessionKey, $ak_id]);
                return $sessionKey;
            } catch (\PDOException $e) {
                // Check if it's a duplicate key error
                if ($e->getCode() === '23000') {
                    // Collision - try again with new key
                    continue;
                }
                throw $e;
            }
        }
        throw new \Exception("Failed to generate unique session key after $maxRetries attempts");
    }

    /**
     * Get activity session by key (owner view - full details)
     *
     * @param string $session_key Session key
     * @param int $user_id User ID for ownership verification
     * @return array|null Session data or null if not found/not owned
     */
    public function getSessionByKey(string $session_key, int $user_id): ?array {
        $stmt = $this->pdo->prepare("
            SELECT ak.ak_id, ak.user_id, ak.activity_id, ak.start_local_dt,
                   ak.intended_sec, ak.actual_sec, ak.bonus_sec,
                   ak.timezone_id, ak.created_at_utc, ak.updated_at_utc,
                   a.activity_name
            FROM activity_session_keys ask
            JOIN activity_kai ak ON ask.ak_id = ak.ak_id
            JOIN activities a ON ak.activity_id = a.activity_id
            WHERE ask.session_key = ? AND ak.user_id = ?
        ");
        $stmt->execute([$session_key, $user_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get public view of session (no user info, privacy-safe)
     *
     * @param string $session_key Session key
     * @return array|null Session data or null if not found/not public
     */
    public function getPublicSessionByKey(string $session_key): ?array {
        $stmt = $this->pdo->prepare("
            SELECT ak.intended_sec, ak.actual_sec, ak.bonus_sec,
                   ak.start_local_dt, ak.is_public,
                   a.activity_name
            FROM activity_session_keys ask
            JOIN activity_kai ak ON ask.ak_id = ak.ak_id
            JOIN activities a ON ak.activity_id = a.activity_id
            WHERE ask.session_key = ?
              AND ak.is_public = 1
        ");
        $stmt->execute([$session_key]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get ak_id from session key (for API endpoints)
     *
     * @param string $session_key Session key
     * @return int|null Activity session ID or null if not found
     */
    public function getAkIdBySessionKey(string $session_key): ?int {
        $stmt = $this->pdo->prepare("
            SELECT ak_id
            FROM activity_session_keys
            WHERE session_key = ?
        ");
        $stmt->execute([$session_key]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? (int)$result['ak_id'] : null;
    }

    /**
     * Check if user owns a session
     *
     * @param string $session_key Session key
     * @param int $user_id User ID
     * @return bool True if user owns the session
     */
    public function isOwner(string $session_key, int $user_id): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM activity_session_keys ask
            JOIN activity_kai ak ON ask.ak_id = ak.ak_id
            WHERE ask.session_key = ? AND ak.user_id = ?
        ");
        $stmt->execute([$session_key, $user_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
}
