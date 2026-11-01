<?php

declare(strict_types=1);

namespace Tests\Unit;

use ActivityTracking\Todo;
use Codeception\Test\Unit;

class TodoSessionCompletionTest extends Unit
{
    private Todo $todo;

    protected function _before(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE todo_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT, todo_id INTEGER, user_id INTEGER, ak_id INTEGER, date_logged TEXT, nth INTEGER)');
        $pdo->exec("INSERT INTO todo_logs (todo_id, user_id, ak_id, date_logged, nth) VALUES (7, 1, 500, '2026-11-01 06:00:00', 2)");
        $this->todo = new Todo($pdo);
    }

    public function testFindsCompletionForSameTodoAndSession(): void
    {
        $this->assertSame(
            ['log_id' => 1, 'nth' => 2, 'date_logged' => '2026-11-01 06:00:00'],
            $this->todo->findCompletionForSession(7, 500)
        );
    }

    public function testOtherSessionFindsNothing(): void
    {
        $this->assertNull($this->todo->findCompletionForSession(7, 501));
    }

    public function testOtherTodoFindsNothing(): void
    {
        $this->assertNull($this->todo->findCompletionForSession(8, 500));
    }
}
