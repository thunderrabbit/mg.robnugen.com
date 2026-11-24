<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Emotional\Sessions;

/**
 * The queries the emotion endpoints (wwwroot/api/v1/_emotions.php) run on
 * interaction_sessions through Emotional\Sessions. The list uses
 * TIMESTAMPDIFF, which SQLite does not have, so it is pinned: its tests
 * compare the SQL text and the parameters, with whitespace collapsed on both
 * sides. The count and the delete are run on an in-memory SQLite, as
 * EmotionVocabQueriesTest does.
 */
class EmotionSessionQueriesTest extends Unit
{
    private const string LIST_HEAD = 'SELECT s.session_id, s.start_time, s.last_event_time,'
        . ' TIMESTAMPDIFF(MINUTE, s.start_time, s.last_event_time) AS duration_minutes,'
        . ' COUNT(e.event_id) AS event_count'
        . ' FROM interaction_sessions s'
        . ' LEFT JOIN interaction_events e ON e.session_id = s.session_id';

    private const string LIST_TAIL = ' GROUP BY s.session_id ORDER BY s.start_time DESC LIMIT ?';

    /**
     * A PDO that accepts one prepare() of exactly this SQL and one execute()
     * of exactly these parameters, and answers with these rows.
     *
     * @param list<mixed> $params
     * @param list<array<string, mixed>> $rows
     */
    private function pdoExpecting(string $sql, array $params, array $rows): \PDO
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with($params)->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('prepare')->with($this->callback(
            static fn (string $given): bool => trim((string) preg_replace('/\s+/', ' ', $given)) === $sql
        ))->willReturn($stmt);
        return $pdo;
    }

    public function testGetSessionsIsPinned(): void
    {
        $row = [
            'session_id' => 10,
            'start_time' => '2026-01-01 10:00:00',
            'last_event_time' => '2026-01-01 10:30:00',
            'duration_minutes' => 30,
            'event_count' => 3,
        ];
        $pdo = $this->pdoExpecting(
            self::LIST_HEAD . ' WHERE s.api_key_id = ?' . self::LIST_TAIL,
            [7, 20],
            [$row]
        );

        $this->assertSame([$row], new Sessions($pdo)->getSessions(7, null, null, 20));
    }

    public function testGetSessionsWithARangeIsPinned(): void
    {
        $pdo = $this->pdoExpecting(
            self::LIST_HEAD . ' WHERE s.api_key_id = ? AND s.start_time >= ? AND s.start_time <= ?' . self::LIST_TAIL,
            [7, '2026-01-01', '2026-01-31', 5],
            []
        );

        $this->assertSame([], new Sessions($pdo)->getSessions(7, '2026-01-01', '2026-01-31', 5));
    }

    public function testGetCountAndDeleteAllAreOnlyTheKeysOwn(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE interaction_sessions (
                session_id INTEGER PRIMARY KEY,
                api_key_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                start_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_event_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $pdo->exec(
            "INSERT INTO interaction_sessions (session_id, api_key_id, user_id) VALUES
                (10, 7, 1), (11, 7, 1), (20, 8, 2)"
        );
        $sessions = new Sessions($pdo);

        $this->assertSame(2, $sessions->getCount(7));

        $sessions->deleteAll(7);

        $this->assertSame(0, $sessions->getCount(7));
        $this->assertSame(1, $sessions->getCount(8));
    }
}
