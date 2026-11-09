<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * In-memory SQLite, same reasoning as LoginThrottleTest: hermetic, no server.
 */
class UserRepositoryTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE users (
                user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    public function testCreateStoresTheUser(): void
    {
        $repo = new \Database\UserRepository($this->pdo);
        $repo->create('rob', 'hash-a', 'admin');

        $stmt = $this->pdo->query("SELECT username, password_hash, role FROM users");
        $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(
            [['username' => 'rob', 'password_hash' => 'hash-a', 'role' => 'admin']],
            $rows
        );
    }

    /**
     * register.php turns SQLSTATE 23000 into "User already exists", so the
     * gateway must let that PDOException through untouched.
     */
    public function testDuplicateUsernameThrowsIntegrityViolation(): void
    {
        $repo = new \Database\UserRepository($this->pdo);
        $repo->create('rob', 'hash-a', 'admin');

        try {
            $repo->create('rob', 'hash-b', 'user');
            $this->fail('A duplicate username was accepted.');
        } catch (\PDOException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }
    }
}
