<?php

declare(strict_types=1);

namespace Database;

/**
 * Persistence for accounts (the `users` table).
 *
 * SQL kept portable so the Unit suite can drive this on an in-memory SQLite.
 */
class UserRepository
{
    public function __construct(private readonly \PDO $di_pdo)
    {
    }

    /**
     * @throws \PDOException SQLSTATE 23000 when the username is taken.
     */
    public function create(string $username, string $password_hash, string $role): void
    {
        $stmt = $this->di_pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmt->execute([$username, $password_hash, $role]);
    }
}
