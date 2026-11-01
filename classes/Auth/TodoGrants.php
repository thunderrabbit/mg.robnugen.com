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
}
