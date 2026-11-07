<?php

/**
 * The todos a limited actor (agent_inbox_user.todos_limited = 1) was granted.
 */

declare(strict_types=1);

namespace Auth;

class TodoGrants
{
    public function __construct(private \PDO $di_pdo)
    {
    }

    /**
     * Ids of the active todos granted to the actor, ascending.
     *
     * @return list<int>
     */
    public function todoIds(int $aiu_id): array
    {
        $stmt = $this->di_pdo->prepare(
            'SELECT g.todo_id FROM aiu_todo_grants g
             JOIN todos t ON t.todo_id = g.todo_id
             WHERE g.aiu_id = :aiu_id AND t.is_active = 1
             ORDER BY g.todo_id'
        );
        $stmt->execute([':aiu_id' => $aiu_id]);
        return array_values(array_map(
            static fn(mixed $v): int => \Field::toInt($v),
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        ));
    }

    /**
     * Distinct, non-null activity ids of the actor's granted active todos.
     *
     * @return list<int>
     */
    public function activityIds(int $aiu_id): array
    {
        $stmt = $this->di_pdo->prepare(
            'SELECT DISTINCT t.activity_id FROM aiu_todo_grants g
             JOIN todos t ON t.todo_id = g.todo_id
             WHERE g.aiu_id = :aiu_id AND t.is_active = 1 AND t.activity_id IS NOT NULL
             ORDER BY t.activity_id'
        );
        $stmt->execute([':aiu_id' => $aiu_id]);
        return array_values(array_map(
            static fn(mixed $v): int => \Field::toInt($v),
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        ));
    }

    /**
     * Active granted todos owned by the user, with how many were logged on
     * the logical day (03:00 to 03:00 the next day).
     *
     * @return list<array{todo_id:int,title:string,activity_id:?int,target_count:int,completed_today:int}>
     */
    public function listGranted(int $aiu_id, int $user_id, string $logical_day): array
    {
        $start = $logical_day . ' 03:00:00';
        $end = new \DateTimeImmutable($logical_day)->modify('+1 day')->format('Y-m-d') . ' 03:00:00';
        $stmt = $this->di_pdo->prepare(
            'SELECT t.todo_id, t.title, t.activity_id, t.target_count,
                    (SELECT COUNT(*) FROM todo_logs l
                     WHERE l.todo_id = t.todo_id
                       AND l.date_logged >= :start AND l.date_logged < :end) AS completed_today
             FROM aiu_todo_grants g
             JOIN todos t ON t.todo_id = g.todo_id
             WHERE g.aiu_id = :aiu_id AND t.user_id = :user_id AND t.is_active = 1
             ORDER BY t.todo_id'
        );
        $stmt->execute([
            ':start' => $start,
            ':end' => $end,
            ':aiu_id' => $aiu_id,
            ':user_id' => $user_id,
        ]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'todo_id' => \Field::int($row, 'todo_id'),
                'title' => \Field::string($row, 'title'),
                'activity_id' => \Field::intOrNull($row, 'activity_id'),
                'target_count' => max(1, \Field::int($row, 'target_count', 1)),
                'completed_today' => \Field::int($row, 'completed_today'),
            ];
        }
        return $rows;
    }
}
