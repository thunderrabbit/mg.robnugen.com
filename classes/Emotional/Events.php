<?php

declare(strict_types=1);

namespace Emotional;

/**
 * The interaction_events table: what an API key logged, in order within a
 * session. `mifmus_id` is the row id of the state an event is tagged with
 * (see Vocab), null for an untagged one, and `encrypted_content` is stored
 * encrypted: this class takes and returns the ciphertext, and Ledger does
 * the encrypting.
 */
class Events
{
    /** The columns updateEvent() may change, in the order it writes them. */
    private const array CHANGEABLE = ['encrypted_content', 'mifmus_id'];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * How many events carry a state, by the state's row id.
     */
    public function getCountTaggedWith(int $mifmus_id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM interaction_events WHERE mifmus_id = ?');
        $stmt->execute([$mifmus_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The next sequence number of a session. Locks the session's events
     * (FOR UPDATE), so it must be called inside the caller's transaction.
     */
    public function nextSequenceNum(int $session_id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM interaction_events WHERE session_id = ? FOR UPDATE'
        );
        $stmt->execute([$session_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Adds an event and returns its event_id.
     */
    public function addEvent(
        int $session_id,
        int $api_key_id,
        int $user_id,
        ?int $mifmus_id,
        string $event_type,
        int $sequence_num,
        string $encrypted_content
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO interaction_events
             (session_id, api_key_id, user_id, mifmus_id, event_type, sequence_num, encrypted_content)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $session_id, $api_key_id, $user_id,
            $mifmus_id, $event_type, $sequence_num, $encrypted_content
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A key's events, oldest first, as event_id, session_id, sequence_num,
     * event_timestamp, event_type, my_id (null for an untagged event) and the
     * still encrypted encrypted_content. Each filter that is not null narrows
     * the list; `$my_id` is the key's own handle for a state.
     *
     * @return list<array<mixed>>
     */
    public function getEvents(
        int $api_key_id,
        ?int $my_id,
        ?int $session_id,
        ?string $from,
        ?string $to,
        ?string $event_type,
        int $limit
    ): array {
        $where = ['e.api_key_id = ?'];
        $params = [$api_key_id];
        $join = 'LEFT JOIN my_ids_for_my_users_state m ON m.mifmus_id = e.mifmus_id';

        if ($my_id !== null) {
            $join = 'INNER JOIN my_ids_for_my_users_state m ON m.mifmus_id = e.mifmus_id AND m.my_id = ?';
            // my_id for the JOIN, then api_key_id for the WHERE
            $params = [$my_id, $api_key_id];
        }
        if ($session_id !== null) {
            $where[] = 'e.session_id = ?';
            $params[] = $session_id;
        }
        if ($from !== null) {
            $where[] = 'e.event_timestamp >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'e.event_timestamp <= ?';
            $params[] = $to;
        }
        if ($event_type !== null) {
            $where[] = 'e.event_type = ?';
            $params[] = $event_type;
        }
        $params[] = $limit;

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT e.event_id, e.session_id, e.sequence_num, e.event_timestamp,
                       e.event_type, m.my_id, e.encrypted_content
                FROM interaction_events e
                $join
                WHERE $where_sql
                ORDER BY e.event_timestamp ASC, e.sequence_num ASC
                LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Deletes one of a key's events. Returns how many rows went: 1, or 0
     * when the key has no such event.
     */
    public function deleteEvent(int $event_id, int $api_key_id): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM interaction_events WHERE event_id = ? AND api_key_id = ?'
        );
        $stmt->execute([$event_id, $api_key_id]);
        return $stmt->rowCount();
    }

    /**
     * Changes the content and/or the tag of one of a key's events. A null
     * `mifmus_id` untags the event, which is not the same as leaving the key
     * out. False when the key has no such event.
     *
     * @param array<string, string|int|null> $changes only `encrypted_content` and/or `mifmus_id`
     * @throws \InvalidArgumentException for no changes, or for any other column
     */
    public function updateEvent(int $event_id, int $api_key_id, array $changes): bool
    {
        $unknown = array_diff(array_keys($changes), self::CHANGEABLE);
        if ($changes === [] || $unknown !== []) {
            throw new \InvalidArgumentException('An event update takes encrypted_content and/or mifmus_id.');
        }

        $sets = [];
        $params = [];
        foreach (self::CHANGEABLE as $column) {
            if (array_key_exists($column, $changes)) {
                $sets[] = $column . ' = ?';
                $params[] = $changes[$column];
            }
        }

        $params[] = $event_id;
        $params[] = $api_key_id;
        $stmt = $this->pdo->prepare(
            'UPDATE interaction_events SET ' . implode(', ', $sets) . ' WHERE event_id = ? AND api_key_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() !== 0;
    }

    /**
     * How many events a key has.
     */
    public function getCount(int $api_key_id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM interaction_events WHERE api_key_id = ?');
        $stmt->execute([$api_key_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every event of a key.
     */
    public function deleteAll(int $api_key_id): void
    {
        $this->pdo->prepare('DELETE FROM interaction_events WHERE api_key_id = ?')->execute([$api_key_id]);
    }
}
