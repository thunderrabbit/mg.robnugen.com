<?php

declare(strict_types=1);

namespace Matrix;

/**
 * The dm_my_evidence table: a user's proofs that an opposite is true.
 * `evidence` is encrypted in the browser; this class takes and returns the
 * blob as it is stored.
 */
class Evidence
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * A user's evidence for an opposite in the order it was made, as dm_me
     * and evidence.
     *
     * @return list<array<mixed>>
     */
    public function getEvidence(int $dm_mo, int $user_id): array
    {
        $stmt = $this->pdo->prepare("SELECT dm_me, evidence FROM dm_my_evidence WHERE dm_mo = ? AND user_id = ? ORDER BY created_at");
        $stmt->execute([$dm_mo, $user_id]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Adds a piece of evidence for an opposite.
     */
    public function addEvidence(int $dm_mo, int $user_id, string $evidence): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO dm_my_evidence (dm_mo, user_id, evidence) VALUES (?, ?, ?)");
        $stmt->execute([$dm_mo, $user_id, $evidence]);
    }

    /**
     * How many pieces of evidence a user has for an opposite.
     */
    public function getCount(int $dm_mo, int $user_id): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM dm_my_evidence WHERE dm_mo = ? AND user_id = ?");
        $stmt->execute([$dm_mo, $user_id]);
        return (int) $stmt->fetchColumn();
    }
}
