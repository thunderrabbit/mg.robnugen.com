<?php

declare(strict_types=1);

namespace Issues;

/**
 * The repositories table: the code repositories an account has entered, so
 * that issues and commits can name one. A repository is turned off
 * (is_active = 0), never deleted. Every query here answers inside one
 * account only.
 */
class Repositories
{
    /** The columns updateRepository() may change, in the order it writes them. */
    private const array CHANGEABLE = ['name', 'url', 'default_branch'];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * An account's repositories by name, the inactive ones only when asked.
     *
     * @return list<array<mixed>>
     */
    public function getRepositories(int $user_id, bool $include_inactive): array
    {
        $where = ['user_id = ?'];
        $params = [$user_id];
        if (!$include_inactive) {
            $where[] = 'is_active = 1';
        }
        $where_sql = implode(' AND ', $where);

        $stmt = $this->pdo->prepare(
            "SELECT repo_id, name, url, default_branch, is_active, created_at_utc
             FROM repositories
             WHERE {$where_sql}
             ORDER BY name ASC"
        );
        $stmt->execute($params);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The repo_id of the account's repository of that name, active or not,
     * or null when it has none.
     */
    public function repoIdOfName(int $user_id, string $name): ?int
    {
        $stmt = $this->pdo->prepare("SELECT repo_id FROM repositories WHERE user_id = ? AND name = ?");
        $stmt->execute([$user_id, $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? \Field::int($row, 'repo_id') : null;
    }

    /**
     * Adds a repository and returns its repo_id. A name the account already
     * uses throws the PDOException of the unique index.
     */
    public function addRepository(int $user_id, string $name, ?string $url, ?string $default_branch): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO repositories (user_id, name, url, default_branch)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$user_id, $name, $url, $default_branch]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Changes a repository's name, url and/or default branch and returns how
     * many rows changed. A null `url` clears it.
     *
     * @param array<string, string|null> $changes only `name`, `url`, `default_branch`
     * @throws \InvalidArgumentException for no changes, or for any other column
     */
    public function updateRepository(int $repo_id, int $user_id, array $changes): int
    {
        $unknown = array_diff(array_keys($changes), self::CHANGEABLE);
        if ($changes === [] || $unknown !== []) {
            throw new \InvalidArgumentException('A repository update takes name, url and/or default_branch.');
        }

        $sets = [];
        $params = [];
        foreach (self::CHANGEABLE as $column) {
            if (array_key_exists($column, $changes)) {
                $sets[] = $column . ' = ?';
                $params[] = $changes[$column];
            }
        }

        $params[] = $repo_id;
        $params[] = $user_id;
        $set_sql = implode(', ', $sets);
        $stmt = $this->pdo->prepare("UPDATE repositories SET {$set_sql} WHERE repo_id = ? AND user_id = ?");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Turns a repository off and returns how many rows changed.
     */
    public function deactivate(int $repo_id, int $user_id): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE repositories SET is_active = 0 WHERE repo_id = ? AND user_id = ?"
        );
        $stmt->execute([$repo_id, $user_id]);
        return $stmt->rowCount();
    }
}
