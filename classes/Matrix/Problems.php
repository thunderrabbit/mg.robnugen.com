<?php

declare(strict_types=1);

namespace Matrix;

/**
 * The dm_my_problems table: a user's limiting beliefs. `problem` is
 * encrypted in the browser; this class takes and returns the blob as it is
 * stored.
 */
class Problems
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Adds a problem and returns its dm_mp.
     */
    public function addProblem(int $user_id, string $problem): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO dm_my_problems (user_id, problem) VALUES (?, ?)");
        $stmt->execute([$user_id, $problem]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A user's problems in the order they were made, as dm_mp and problem.
     *
     * @return list<array<mixed>>
     */
    public function getProblems(int $user_id): array
    {
        $stmt = $this->pdo->prepare("SELECT dm_mp, problem FROM dm_my_problems WHERE user_id = ? ORDER BY created_at");
        $stmt->execute([$user_id]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * One of a user's problems as dm_mp and problem, or null when the user
     * has no such problem.
     *
     * @return array<mixed>|null
     */
    public function getProblem(int $dm_mp, int $user_id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT dm_mp, problem FROM dm_my_problems WHERE dm_mp = ? AND user_id = ?");
        $stmt->execute([$dm_mp, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
