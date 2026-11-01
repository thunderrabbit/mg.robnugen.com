<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\TodoGrants;
use Codeception\Test\Unit;

class TodoGrantsTest extends Unit
{
    private TodoGrants $grants;

    protected function _before(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE todos (todo_id INTEGER PRIMARY KEY, user_id INTEGER, activity_id INTEGER, is_active INTEGER)');
        $pdo->exec('CREATE TABLE aiu_todo_grants (atg_id INTEGER PRIMARY KEY AUTOINCREMENT, aiu_id INTEGER, todo_id INTEGER)');
        $pdo->exec('INSERT INTO todos VALUES (7, 1, 1, 1), (8, 1, 2, 0), (9, 1, NULL, 1)');
        $pdo->exec('INSERT INTO aiu_todo_grants (aiu_id, todo_id) VALUES (70, 9), (70, 8), (70, 7)');
        $this->grants = new TodoGrants($pdo);
    }

    public function testTodoIdsSkipsInactiveTodos(): void
    {
        $this->assertSame([7, 9], $this->grants->todoIds(70));
    }

    public function testActivityIdsAreDistinctAndNonNull(): void
    {
        $this->assertSame([1], $this->grants->activityIds(70));
    }

    public function testActorWithNoGrantsGetsEmptyLists(): void
    {
        $this->assertSame([], $this->grants->todoIds(71));
        $this->assertSame([], $this->grants->activityIds(71));
    }
}
