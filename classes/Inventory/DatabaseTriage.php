<?php

declare(strict_types=1);

namespace Inventory;

/**
 * Writes Rob's calls on databases (mg #775). dh_triage is append-only: a call
 * is a new row, taking one back is a new 'undecided' row, and nothing is ever
 * updated or deleted. Rules about WHICH databases may be marked live in
 * EmptyDatabaseRule; this only records what it is handed.
 */
final class DatabaseTriage
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param list<int> $databaseIds
     */
    public function markForDeletion(array $databaseIds, string $note): void
    {
        $this->record($databaseIds, 'delete', $note);
    }

    public function unmark(int $databaseId): void
    {
        $this->record([$databaseId], 'undecided', 'taken back');
    }

    /**
     * @param list<int> $databaseIds
     */
    private function record(array $databaseIds, string $decision, string $note): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO dh_triage (dh_database_id, decision, note) VALUES (?, ?, ?)',
        );
        $this->pdo->beginTransaction();
        try {
            foreach ($databaseIds as $databaseId) {
                $insert->execute([$databaseId, $decision, $note]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
