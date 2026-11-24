<?php

declare(strict_types=1);

namespace Auth;

/**
 * The agent_inbox_user table: the actors of an account, one human and any
 * number of agents, each with its own permission bits. Every lookup here
 * that takes a user_id answers only inside that account, so an aiu_id from
 * another account is the same as one that does not exist.
 */
class Actors
{
    /** The permission bits addAgent() stores, in the order of its columns. */
    private const array PERMISSIONS = [
        'can_read_inbox', 'can_write_inbox',
        'can_read_todos', 'can_write_todos',
        'can_read_sessions', 'can_write_sessions',
        'can_read_emotions', 'can_write_emotions',
        'can_read_project', 'can_write_project',
        'can_ping_phone',
    ];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * An actor's whole row, or null when the account has no such actor.
     *
     * @return array<string, mixed>|null
     */
    public function getActor(int $aiu_id, int $user_id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$aiu_id, $user_id]);
        return self::row($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * The whole row of the actor an API key acts as, or null when the key
     * names no actor of its own account.
     *
     * @return array<string, mixed>|null
     */
    public function getActorOfKey(int $key_id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.* FROM agent_inbox_user a
             JOIN api_keys k ON k.aiu_id = a.aiu_id AND a.user_id = k.user_id
             WHERE k.key_id = ?"
        );
        $stmt->execute([$key_id]);
        return self::row($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * An account's actors in aiu_id order, with the columns the agents
     * endpoint lists.
     *
     * @return list<array<mixed>>
     */
    public function getAgents(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT aiu_id, name, description, actor_type,
                    can_read_inbox, can_write_inbox,
                    can_read_todos, can_write_todos,
                    can_read_sessions, can_write_sessions,
                    can_read_emotions, can_write_emotions,
                    can_read_project, can_write_project,
                    can_ping_phone,
                    created_at_utc
             FROM agent_inbox_user WHERE user_id = ? ORDER BY aiu_id"
        );
        $stmt->execute([$user_id]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The actors of an account that an OAuth client may be given: those
     * that can read or write projects, the human first, then by name.
     *
     * @return list<array<mixed>>
     */
    public function getGrantable(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT aiu_id, name, actor_type, description
             FROM agent_inbox_user
             WHERE user_id = ? AND (can_read_project = 1 OR can_write_project = 1)
             ORDER BY actor_type DESC, name ASC"
        );
        $stmt->execute([$user_id]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The account's human actor as aiu_id and can_broadcast_inbox, or null
     * when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function getHumanActor(int $user_id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT aiu_id, can_broadcast_inbox FROM agent_inbox_user WHERE user_id = ? AND actor_type = 'human' LIMIT 1"
        );
        $stmt->execute([$user_id]);
        return self::row($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * An account's actors, oldest first, as aiu_id and name: the inbox
     * page's recipient list.
     *
     * @return list<array<mixed>>
     */
    public function getActorsOldestFirst(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT aiu_id, name FROM agent_inbox_user WHERE user_id = ? ORDER BY created_at_utc ASC"
        );
        $stmt->execute([$user_id]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * An account's actors in name order, as aiu_id, name and description:
     * what a sender needs to pick a recipient.
     *
     * @return list<array<mixed>>
     */
    public function getActorsByName(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT aiu_id, name, description FROM agent_inbox_user WHERE user_id = ? ORDER BY name"
        );
        $stmt->execute([$user_id]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The aiu_id of the account's actor of that name, or null when it has
     * none.
     */
    public function aiuIdOfName(int $user_id, string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND name = ?"
        );
        $stmt->execute([$user_id, $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? \Field::int($row, 'aiu_id') : null;
    }

    /**
     * The aiu_id of the account's limited actor (todos_limited = 1) of that
     * name, or null when it has none.
     */
    public function limitedAiuIdOfName(int $user_id, string $name): ?int
    {
        $stmt = $this->pdo->prepare("SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND name = ? AND todos_limited = 1 LIMIT 1");
        $stmt->execute([$user_id, $name]);
        $aiu_id = $stmt->fetchColumn();
        return $aiu_id === false ? null : \Field::toInt($aiu_id);
    }

    /**
     * Whether the actor belongs to the account.
     */
    public function isInAccount(int $aiu_id, int $user_id): bool
    {
        $stmt = $this->pdo->prepare("SELECT aiu_id FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ?");
        $stmt->execute([$aiu_id, $user_id]);
        return $stmt->fetch() !== false;
    }

    /**
     * An actor's name and actor_type, or null when the account has no such
     * actor.
     *
     * @return array<string, mixed>|null
     */
    public function nameAndTypeOf(int $aiu_id, int $user_id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT name, actor_type FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ?");
        $stmt->execute([$aiu_id, $user_id]);
        return self::row($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * Adds an agent to an account and returns its aiu_id. A name the account
     * already uses throws the PDOException of the unique index.
     *
     * @param array<string, int> $perms one 0 or 1 for each of the eleven permission bits
     */
    public function addAgent(int $user_id, string $name, ?string $description, array $perms): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO agent_inbox_user (user_id, name, description, actor_type,
             can_read_inbox, can_write_inbox, can_read_todos, can_write_todos,
             can_read_sessions, can_write_sessions, can_read_emotions, can_write_emotions,
             can_read_project, can_write_project, can_ping_phone)
             VALUES (?, ?, ?, 'agent', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $params = [$user_id, $name, $description];
        foreach (self::PERMISSIONS as $permission) {
            $params[] = $perms[$permission];
        }
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function row(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        /** @var array<string, mixed> $row */
        return $row;
    }
}
