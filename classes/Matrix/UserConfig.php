<?php

declare(strict_types=1);

namespace Matrix;

/**
 * The dm_user_config table: one row per user who has set up a Decision
 * Matrix. `passphrase_verify` is a known string the browser encrypted with
 * the user's passphrase; the server never sees the passphrase, and this
 * class takes and returns the blob as it is stored.
 */
class UserConfig
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * 1 when the user has set up a passphrase, 0 when not.
     */
    public function getCount(int $user_id): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM dm_user_config WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The user's passphrase check blob, or null when they have not set one
     * up.
     */
    public function passphraseVerifyOf(int $user_id): ?string
    {
        $stmt = $this->pdo->prepare("SELECT passphrase_verify FROM dm_user_config WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $verify = $stmt->fetchColumn();
        return is_string($verify) ? $verify : null;
    }

    /**
     * Stores the passphrase check blob of a user who has none yet.
     */
    public function addConfig(int $user_id, string $passphrase_verify): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO dm_user_config (user_id, passphrase_verify) VALUES (?, ?)");
        $stmt->execute([$user_id, $passphrase_verify]);
    }
}
