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
        $pdo->exec('CREATE TABLE todos (todo_id INTEGER PRIMARY KEY, user_id INTEGER, activity_id INTEGER, is_active INTEGER, title TEXT, target_count INTEGER)');
        $pdo->exec('CREATE TABLE todo_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT, todo_id INTEGER, date_logged TEXT)');
        $pdo->exec('CREATE TABLE aiu_todo_grants (atg_id INTEGER PRIMARY KEY AUTOINCREMENT, aiu_id INTEGER, todo_id INTEGER)');
        $pdo->exec('INSERT INTO todos VALUES (7, 1, 1, 1, \'Meditate\', 2), (8, 1, 2, 0, \'Old\', 1), (9, 1, NULL, 1, \'Other\', 1)');
        $pdo->exec("INSERT INTO todo_logs (todo_id, date_logged) VALUES (7, '2026-11-01 06:00:00'), (7, '2026-11-02 01:30:00'), (7, '2026-11-02 03:00:00')");
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

    public function testListGrantedCountsByLogicalDay(): void
    {
        $rows = $this->grants->listGranted(70, 1, '2026-11-01');
        $this->assertSame([7, 9], array_column($rows, 'todo_id'));
        $this->assertSame('Meditate', $rows[0]['title']);
        $this->assertSame(1, $rows[0]['activity_id']);
        $this->assertSame(2, $rows[0]['target_count']);
        $this->assertSame(2, $rows[0]['completed_today']);
        $this->assertNull($rows[1]['activity_id']);
        $this->assertSame(0, $rows[1]['completed_today']);
    }

    public function testListGrantedIsScopedToUser(): void
    {
        $this->assertSame([], $this->grants->listGranted(70, 2, '2026-11-01'));
    }
}
