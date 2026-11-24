<?php

declare(strict_types=1);

namespace Emotional;

/**
 * The interaction_sessions table: one row per run of an API key's events
 * that came close together. Ledger::getOrCreateSession() finds or opens the
 * current one; this class lists, counts and deletes them.
 */
class Sessions
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * A key's sessions, newest first, as session_id, start_time,
     * last_event_time, duration_minutes and event_count. `$from` and `$to`,
     * when not null, bound the start_time.
     *
     * @return list<array<mixed>>
     */
    public function getSessions(int $api_key_id, ?string $from, ?string $to, int $limit): array
    {
        $where = ['s.api_key_id = ?'];
        $params = [$api_key_id];

        if ($from !== null) {
            $where[] = 's.start_time >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 's.start_time <= ?';
            $params[] = $to;
        }
        $params[] = $limit;

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT
                    s.session_id, s.start_time, s.last_event_time,
                    TIMESTAMPDIFF(MINUTE, s.start_time, s.last_event_time) AS duration_minutes,
                    COUNT(e.event_id) AS event_count
                FROM interaction_sessions s
                LEFT JOIN interaction_events e ON e.session_id = s.session_id
                WHERE $where_sql
                GROUP BY s.session_id
                ORDER BY s.start_time DESC
                LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * How many sessions a key has.
     */
    public function getCount(int $api_key_id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM interaction_sessions WHERE api_key_id = ?');
        $stmt->execute([$api_key_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every session of a key.
     */
    public function deleteAll(int $api_key_id): void
    {
        $this->pdo->prepare('DELETE FROM interaction_sessions WHERE api_key_id = ?')->execute([$api_key_id]);
    }
}
