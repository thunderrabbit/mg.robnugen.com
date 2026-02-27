<?php

namespace Emotional;

class Ledger {

    private string $encKey;
    private \PDO $pdo;
    private int $api_key_id;
    private int $user_id;

    public function __construct(\PDO $pdo, string $raw_key, int $api_key_id, int $user_id) {
        $this->pdo = $pdo;
        $this->api_key_id = $api_key_id;
        $this->user_id = $user_id;
        $this->encKey = hash_hmac('sha256', 'emotional_v1', $raw_key, true); // 32 bytes
    }

    /**
     * Encrypt a plaintext string using XSalsa20-Poly1305 (sodium secretbox).
     * Each call uses a fresh random nonce — identical plaintext produces different ciphertext.
     */
    public function encrypt(string $plaintext): string {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // 24 bytes
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->encKey);
        return base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a stored blob. Returns false on authentication failure (wrong key or tampered data).
     */
    public function decrypt(string $stored): string|false {
        $decoded = base64_decode($stored);
        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        $nonce  = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return sodium_crypto_secretbox_open($cipher, $nonce, $this->encKey);
    }

    /**
     * Find the current session or create a new one.
     * A session is "current" if its last_event_time is within EMOTIONAL_SESSION_GAP_MINUTES.
     * Must be called within a transaction (caller's responsibility).
     */
    public function getOrCreateSession(): int {
        $gap = intval(EMOTIONAL_SESSION_GAP_MINUTES);

        $stmt = $this->pdo->prepare(
            "SELECT session_id FROM interaction_sessions
             WHERE api_key_id = ?
               AND last_event_time > DATE_SUB(NOW(), INTERVAL {$gap} MINUTE)
             ORDER BY last_event_time DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$this->api_key_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            $session_id = (int) $row['session_id'];
            $this->pdo->prepare(
                'UPDATE interaction_sessions SET last_event_time = NOW() WHERE session_id = ?'
            )->execute([$session_id]);
            return $session_id;
        }

        $this->pdo->prepare(
            'INSERT INTO interaction_sessions (api_key_id, user_id) VALUES (?, ?)'
        )->execute([$this->api_key_id, $this->user_id]);
        return (int) $this->pdo->lastInsertId();
    }
}
