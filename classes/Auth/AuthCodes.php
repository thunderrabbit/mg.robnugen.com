<?php

declare(strict_types=1);

namespace Auth;

/**
 * The oauth_auth_codes table as the token endpoint reads it: the single-use
 * codes the authorize page hands out, stored as SHA-256 hashes. AuthCode
 * makes one; this class finds it again and claims it.
 */
class AuthCodes
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * The whole row of a code that has not expired and was not used, or
     * null.
     *
     * @return array<string, mixed>|null
     */
    public function getUsable(string $code_hash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM oauth_auth_codes
             WHERE code_hash = ? AND expires_at_utc > NOW() AND used_at_utc IS NULL
             LIMIT 1"
        );
        $stmt->execute([$code_hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Claims a code. The used_at_utc IS NULL condition makes the claim
     * atomic: of two requests racing with the same code, only the one whose
     * UPDATE changes the row gets true.
     */
    public function markUsed(int $code_id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE oauth_auth_codes SET used_at_utc = NOW() WHERE code_id = ? AND used_at_utc IS NULL");
        $stmt->execute([$code_id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Deletes the codes that expired more than a day ago.
     */
    public function deleteOld(): void
    {
        $this->pdo->prepare("DELETE FROM oauth_auth_codes WHERE expires_at_utc < DATE_SUB(NOW(), INTERVAL 1 DAY)")->execute();
    }
}
