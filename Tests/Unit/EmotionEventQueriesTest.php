<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Emotional\Events;

/**
 * The queries the emotion endpoints (wwwroot/api/v1/_emotions.php) run on
 * interaction_events through Emotional\Events. All but one are run on an
 * in-memory SQLite (no server, gone with the process), as
 * EmotionVocabQueriesTest does. The one SQLite cannot run is pinned instead:
 * its test says so.
 *
 * Key 7 has session 10 with events 1 to 3 (tagged with its handles 111 and
 * 222, and one untagged); key 8 has session 20 with event 4, tagged with its
 * own handle 111.
 */
class EmotionEventQueriesTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE my_ids_for_my_users_state (
                mifmus_id INTEGER PRIMARY KEY,
                api_key_id INTEGER NOT NULL,
                my_id INTEGER NOT NULL,
                state TEXT NOT NULL,
                UNIQUE (api_key_id, my_id)
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE interaction_sessions (
                session_id INTEGER PRIMARY KEY,
                api_key_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                start_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_event_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE interaction_events (
                event_id INTEGER PRIMARY KEY,
                session_id INTEGER NOT NULL,
                api_key_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                mifmus_id INTEGER NULL,
                event_timestamp TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                event_type TEXT NOT NULL,
                sequence_num INTEGER NOT NULL,
                encrypted_content TEXT NOT NULL
            )"
        );
        $this->pdo->exec(
            "INSERT INTO my_ids_for_my_users_state (mifmus_id, api_key_id, my_id, state) VALUES
                (1, 7, 111, 'cipher-calm'),
                (2, 7, 222, 'cipher-curious'),
                (3, 8, 111, 'cipher-other')"
        );
        $this->pdo->exec(
            "INSERT INTO interaction_sessions (session_id, api_key_id, user_id) VALUES (10, 7, 1), (20, 8, 2)"
        );
        $this->pdo->exec(
            "INSERT INTO interaction_events
                (event_id, session_id, api_key_id, user_id, mifmus_id, event_timestamp,
                 event_type, sequence_num, encrypted_content) VALUES
                (1, 10, 7, 1, 1, '2026-01-01 10:00:00', 'agent_action', 1, 'c1'),
                (2, 10, 7, 1, NULL, '2026-01-01 10:05:00', 'user_input', 2, 'c2'),
                (3, 10, 7, 1, 2, '2026-01-02 09:00:00', 'user_reaction', 3, 'c3'),
                (4, 20, 8, 2, 3, '2026-01-01 10:01:00', 'agent_action', 1, 'c4')"
        );
    }

    /**
     * @return array<mixed>
     */
    private function eventRow(int $event_id): array
    {
        $stmt = $this->pdo->query("SELECT * FROM interaction_events WHERE event_id = $event_id");
        $this->assertNotFalse($stmt);
        $rows = \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    private function eventCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM interaction_events');
        $this->assertNotFalse($stmt);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array<mixed>> $events
     * @return list<mixed>
     */
    private function idsOf(array $events): array
    {
        return array_column($events, 'event_id');
    }

    public function testGetCountTaggedWithCountsOnlyThatState(): void
    {
        $events = new Events($this->pdo);

        $this->assertSame(1, $events->getCountTaggedWith(1));
        $this->assertSame(0, $events->getCountTaggedWith(99));
    }

    /**
     * Pinned, not run: SQLite has no FOR UPDATE. Whitespace is collapsed on
     * both sides, so only the words and their order are compared.
     */
    public function testNextSequenceNumIsPinned(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([10])->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('4');

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('prepare')->with($this->callback(
            static fn (string $sql): bool => trim((string) preg_replace('/\s+/', ' ', $sql))
                === 'SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM interaction_events'
                . ' WHERE session_id = ? FOR UPDATE'
        ))->willReturn($stmt);

        $this->assertSame(4, new Events($pdo)->nextSequenceNum(10));
    }

    public function testAddEventStoresTheRowAndReturnsItsId(): void
    {
        $event_id = new Events($this->pdo)->addEvent(10, 7, 1, null, 'user_input', 4, 'c5');

        $this->assertSame(5, $event_id);
        $row = $this->eventRow(5);
        unset($row['event_timestamp']);
        $this->assertSame(
            [
                'event_id' => 5,
                'session_id' => 10,
                'api_key_id' => 7,
                'user_id' => 1,
                'mifmus_id' => null,
                'event_type' => 'user_input',
                'sequence_num' => 4,
                'encrypted_content' => 'c5',
            ],
            $row
        );
    }

    public function testGetEventsAreTheKeysOwnOldestFirst(): void
    {
        $events = new Events($this->pdo)->getEvents(7, null, null, null, null, null, 50);

        $this->assertSame([1, 2, 3], $this->idsOf($events));
        $this->assertSame([111, null, 222], array_column($events, 'my_id'));
        $this->assertSame(
            ['event_id', 'session_id', 'sequence_num', 'event_timestamp', 'event_type', 'my_id', 'encrypted_content'],
            array_keys($events[0])
        );
    }

    public function testGetEventsByHandle(): void
    {
        $events = new Events($this->pdo);

        $this->assertSame([1], $this->idsOf($events->getEvents(7, 111, null, null, null, null, 50)));
        $this->assertSame([4], $this->idsOf($events->getEvents(8, 111, null, null, null, null, 50)));
        $this->assertSame([], $events->getEvents(8, 222, null, null, null, null, 50));
    }

    public function testGetEventsBySessionRangeAndType(): void
    {
        $events = new Events($this->pdo);

        $this->assertSame(
            [2, 3],
            $this->idsOf($events->getEvents(7, null, 10, '2026-01-01 10:05:00', null, null, 50))
        );
        $this->assertSame(
            [2],
            $this->idsOf($events->getEvents(7, null, 10, '2026-01-01 10:05:00', '2026-01-01 23:59:59', null, 50))
        );
        $this->assertSame([1], $this->idsOf($events->getEvents(7, null, null, null, null, 'agent_action', 50)));
    }

    public function testGetEventsStopsAtTheLimit(): void
    {
        $events = new Events($this->pdo)->getEvents(7, null, null, null, null, null, 2);

        $this->assertSame([1, 2], $this->idsOf($events));
    }

    public function testDeleteEventOnlyOfTheKeysOwn(): void
    {
        $events = new Events($this->pdo);

        $this->assertSame(0, $events->deleteEvent(1, 8));
        $this->assertSame(4, $this->eventCount());

        $this->assertSame(1, $events->deleteEvent(1, 7));
        $this->assertSame(3, $this->eventCount());
    }

    public function testUpdateEventChangesContentAndTag(): void
    {
        $changed = new Events($this->pdo)->updateEvent(2, 7, ['encrypted_content' => 'c2b', 'mifmus_id' => 2]);

        $this->assertTrue($changed);
        $row = $this->eventRow(2);
        $this->assertSame('c2b', $row['encrypted_content']);
        $this->assertSame(2, $row['mifmus_id']);
    }

    public function testUpdateEventUntagsWithNull(): void
    {
        $this->assertTrue(new Events($this->pdo)->updateEvent(1, 7, ['mifmus_id' => null]));

        $row = $this->eventRow(1);
        $this->assertNull($row['mifmus_id']);
        $this->assertSame('c1', $row['encrypted_content']);
    }

    public function testUpdateEventIsFalseForAnotherKeysEvent(): void
    {
        $this->assertFalse(new Events($this->pdo)->updateEvent(4, 7, ['encrypted_content' => 'x']));

        $this->assertSame('c4', $this->eventRow(4)['encrypted_content']);
    }

    public function testUpdateEventRefusesNoChangesAndUnknownColumns(): void
    {
        $events = new Events($this->pdo);
        $before = $this->eventRow(1);

        foreach ([[], ['user_id' => 9]] as $changes) {
            try {
                $events->updateEvent(1, 7, $changes);
                $this->fail('Only encrypted_content and mifmus_id may be changed, and one must be.');
            } catch (\InvalidArgumentException) {
                $this->assertSame($before, $this->eventRow(1));
            }
        }
    }

    public function testGetCountAndDeleteAllAreOnlyTheKeysOwn(): void
    {
        $events = new Events($this->pdo);

        $this->assertSame(3, $events->getCount(7));

        $events->deleteAll(7);

        $this->assertSame(0, $events->getCount(7));
        $this->assertSame(1, $events->getCount(8));
    }
}
