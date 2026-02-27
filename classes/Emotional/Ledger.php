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
}
