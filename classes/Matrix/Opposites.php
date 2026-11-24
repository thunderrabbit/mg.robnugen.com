<?php

declare(strict_types=1);

namespace Matrix;

/**
 * The dm_my_opposites table: the empowering belief a user set against a
 * problem, at most one per problem. `opposite` is encrypted in the browser;
 * this class takes and returns the blob as it is stored.
 */
class Opposites
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * The user's opposite of a problem as dm_mo and opposite, or null when
     * the problem has none of theirs.
     *
     * @return array<mixed>|null
     */
    public function getOpposite(int $dm_mp, int $user_id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT dm_mo, opposite FROM dm_my_opposites WHERE dm_mp = ? AND user_id = ?");
        $stmt->execute([$dm_mp, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * One of a user's opposites with the problem it answers, as dm_mo,
     * opposite, dm_mp and problem, or null when the user has no such
     * opposite.
     *
     * @return array<mixed>|null
     */
    public function getOppositeWithProblem(int $dm_mo, int $user_id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT o.dm_mo, o.opposite, o.dm_mp, p.problem
            FROM dm_my_opposites o
            JOIN dm_my_problems p ON o.dm_mp = p.dm_mp
            WHERE o.dm_mo = ? AND o.user_id = ?");
        $stmt->execute([$dm_mo, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * 1 when the problem already has an opposite, whoever's, 0 when not.
     */
    public function getCountForProblem(int $dm_mp): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM dm_my_opposites WHERE dm_mp = ?");
        $stmt->execute([$dm_mp]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Adds a problem's opposite and returns its dm_mo.
     */
    public function addOpposite(int $dm_mp, int $user_id, string $opposite): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO dm_my_opposites (dm_mp, user_id, opposite) VALUES (?, ?, ?)");
        $stmt->execute([$dm_mp, $user_id, $opposite]);
        return (int) $this->pdo->lastInsertId();
    }
}
