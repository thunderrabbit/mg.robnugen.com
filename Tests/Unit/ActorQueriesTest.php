<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\Actors;
use Codeception\Test\Unit;
use Inbox\Visibility;

/**
 * The queries the agents endpoint (wwwroot/api/v1/_agents.php) and the four
 * entry scripts that look an actor up run through Auth\Actors and
 * Inbox\Visibility, over an in-memory SQLite (no server, gone with the
 * process), as EmotionVocabQueriesTest does. Every one of them runs there.
 *
 * Account 1 has actors 10 (Rob, human), 11 (Helper, agent) and 12 (Bells, a
 * limited agent); account 2 has actor 20 (Helper, agent). Key 100 is actor
 * 11's, key 101 names actor 20 from account 1 (a mismatch), key 200 is actor
 * 20's. Actor 10 is a supervisor; 11 may read its own inbox and send to 10.
 */
class ActorQueriesTest extends Unit
{
    private const array PERMS = [
        'can_read_inbox' => 1,
        'can_write_inbox' => 0,
        'can_read_todos' => 0,
        'can_write_todos' => 1,
        'can_read_sessions' => 0,
        'can_write_sessions' => 0,
        'can_read_emotions' => 1,
        'can_write_emotions' => 0,
        'can_read_project' => 0,
        'can_write_project' => 1,
        'can_ping_phone' => 0,
    ];

    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE agent_inbox_user (
                aiu_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT NULL,
                actor_type TEXT NOT NULL DEFAULT 'agent',
                can_read_inbox INTEGER NOT NULL DEFAULT 0,
                can_write_inbox INTEGER NOT NULL DEFAULT 0,
                can_read_todos INTEGER NOT NULL DEFAULT 0,
                can_write_todos INTEGER NOT NULL DEFAULT 0,
                todos_limited INTEGER NOT NULL DEFAULT 0,
                can_read_sessions INTEGER NOT NULL DEFAULT 0,
                can_write_sessions INTEGER NOT NULL DEFAULT 0,
                can_read_emotions INTEGER NOT NULL DEFAULT 0,
                can_write_emotions INTEGER NOT NULL DEFAULT 0,
                can_read_project INTEGER NOT NULL DEFAULT 0,
                can_write_project INTEGER NOT NULL DEFAULT 0,
                can_ping_phone INTEGER NOT NULL DEFAULT 0,
                created_at_utc TEXT NOT NULL DEFAULT '2026-01-01 00:00:00',
                UNIQUE (user_id, name)
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE api_keys (key_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, aiu_id INTEGER NULL)"
        );
        $this->pdo->exec(
            "CREATE TABLE inbox_visibility (
                inbox_user_aiu_id INTEGER NOT NULL,
                inbox_peer_aiu_id INTEGER NULL,
                can_read INTEGER NOT NULL DEFAULT 0,
                can_send INTEGER NOT NULL DEFAULT 0
            )"
        );
        $this->pdo->exec(
            "INSERT INTO agent_inbox_user (aiu_id, user_id, name, description, actor_type, todos_limited) VALUES
                (10, 1, 'Rob', NULL, 'human', 0),
                (11, 1, 'Helper', 'helps', 'agent', 0),
                (12, 1, 'Bells', NULL, 'agent', 1),
                (20, 2, 'Helper', NULL, 'agent', 0)"
        );
        $this->pdo->exec("INSERT INTO api_keys (key_id, user_id, aiu_id) VALUES (100, 1, 11), (101, 1, 20), (200, 2, 20)");
        $this->pdo->exec(
            "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send) VALUES
                (10, NULL, 1, 1),
                (11, 11, 1, 0),
                (11, 10, 0, 1),
                (12, NULL, 0, 1)"
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private function allRows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $this->assertNotFalse($stmt);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testGetActorIsTheWholeRowOnlyInsideItsAccount(): void
    {
        $actors = new Actors($this->pdo);

        $actor = $actors->getActor(12, 1);
        $this->assertNotNull($actor);
        $this->assertSame('Bells', $actor['name']);
        $this->assertSame(1, $actor['todos_limited']);
        $this->assertArrayHasKey('created_at_utc', $actor);

        $this->assertNull($actors->getActor(20, 1));
        $this->assertNull($actors->getActor(12, 2));
        $this->assertNull($actors->getActor(99, 1));
    }

    public function testGetActorOfKeyIsNullWhenTheKeyNamesAnotherAccountsActor(): void
    {
        $actors = new Actors($this->pdo);

        $actor = $actors->getActorOfKey(100);
        $this->assertNotNull($actor);
        $this->assertSame(11, $actor['aiu_id']);
        $this->assertSame(1, $actor['user_id']);
        $this->assertArrayNotHasKey('key_id', $actor);

        $this->assertNull($actors->getActorOfKey(101));
        $this->assertNull($actors->getActorOfKey(999));
    }

    public function testGetAgentsAreTheAccountsOwnInAiuOrder(): void
    {
        $agents = new Actors($this->pdo)->getAgents(1);

        $this->assertSame([10, 11, 12], array_column($agents, 'aiu_id'));
        $this->assertSame(
            [
                'aiu_id', 'name', 'description', 'actor_type',
                'can_read_inbox', 'can_write_inbox',
                'can_read_todos', 'can_write_todos',
                'can_read_sessions', 'can_write_sessions',
                'can_read_emotions', 'can_write_emotions',
                'can_read_project', 'can_write_project',
                'can_ping_phone',
                'created_at_utc',
            ],
            array_keys($agents[0])
        );
    }

    public function testAiuIdOfNameIsWithinTheAccount(): void
    {
        $actors = new Actors($this->pdo);

        $this->assertSame(11, $actors->aiuIdOfName(1, 'Helper'));
        $this->assertSame(20, $actors->aiuIdOfName(2, 'Helper'));
        $this->assertNull($actors->aiuIdOfName(2, 'Rob'));
    }

    public function testLimitedAiuIdOfNameIsOnlyALimitedActor(): void
    {
        $actors = new Actors($this->pdo);

        $this->assertSame(12, $actors->limitedAiuIdOfName(1, 'Bells'));
        $this->assertNull($actors->limitedAiuIdOfName(1, 'Helper'));
        $this->assertNull($actors->limitedAiuIdOfName(2, 'Bells'));
    }

    public function testIsInAccount(): void
    {
        $actors = new Actors($this->pdo);

        $this->assertTrue($actors->isInAccount(11, 1));
        $this->assertFalse($actors->isInAccount(20, 1));
        $this->assertFalse($actors->isInAccount(11, 2));
    }

    public function testNameAndTypeOfIsOnlyInsideTheAccount(): void
    {
        $actors = new Actors($this->pdo);

        $this->assertSame(['name' => 'Rob', 'actor_type' => 'human'], $actors->nameAndTypeOf(10, 1));
        $this->assertNull($actors->nameAndTypeOf(20, 1));
    }

    public function testAddAgentStoresAnAgentWithEachPermissionInItsOwnColumn(): void
    {
        $aiu_id = new Actors($this->pdo)->addAgent(2, 'Newcomer', 'new here', self::PERMS);

        $this->assertSame(21, $aiu_id);
        $row = $this->allRows('SELECT * FROM agent_inbox_user WHERE aiu_id = 21')[0];
        $this->assertSame(2, $row['user_id']);
        $this->assertSame('Newcomer', $row['name']);
        $this->assertSame('new here', $row['description']);
        $this->assertSame('agent', $row['actor_type']);
        $this->assertSame(0, $row['todos_limited']);
        $this->assertSame(self::PERMS, array_intersect_key($row, self::PERMS));
    }

    public function testAddAgentKeepsANullDescription(): void
    {
        $aiu_id = new Actors($this->pdo)->addAgent(2, 'Quiet', null, self::PERMS);

        $this->assertNull($this->allRows("SELECT description FROM agent_inbox_user WHERE aiu_id = $aiu_id")[0]['description']);
    }

    public function testIsSupervisorNeedsAnAllActorsRowThatCanRead(): void
    {
        $visibility = new Visibility($this->pdo);

        $this->assertTrue($visibility->isSupervisor(10));
        $this->assertFalse($visibility->isSupervisor(11));
        $this->assertFalse($visibility->isSupervisor(12));
    }

    public function testAddOwnRowLetsAnActorReadItsOwnInboxOnly(): void
    {
        new Visibility($this->pdo)->addOwnRow(20);

        $this->assertSame(
            [['inbox_user_aiu_id' => 20, 'inbox_peer_aiu_id' => 20, 'can_read' => 1, 'can_send' => 0]],
            $this->allRows('SELECT * FROM inbox_visibility WHERE inbox_user_aiu_id = 20')
        );
    }

    public function testAddSendRowLetsAnActorSendToAPeerOnly(): void
    {
        new Visibility($this->pdo)->addSendRow(20, 10);

        $this->assertSame(
            [['inbox_user_aiu_id' => 20, 'inbox_peer_aiu_id' => 10, 'can_read' => 0, 'can_send' => 1]],
            $this->allRows('SELECT * FROM inbox_visibility WHERE inbox_user_aiu_id = 20')
        );
    }

    public function testHasRowIsForThatPairInThatDirection(): void
    {
        $visibility = new Visibility($this->pdo);

        $this->assertTrue($visibility->hasRow(11, 10));
        $this->assertFalse($visibility->hasRow(10, 11));
        $this->assertFalse($visibility->hasRow(11, 12));
    }

    public function testUpdateRowChangesOnlyThatPair(): void
    {
        new Visibility($this->pdo)->updateRow(11, 10, 1, 0);

        $this->assertSame(
            [
                ['inbox_peer_aiu_id' => 10, 'can_read' => 1, 'can_send' => 0],
                ['inbox_peer_aiu_id' => 11, 'can_read' => 1, 'can_send' => 0],
            ],
            $this->allRows(
                'SELECT inbox_peer_aiu_id, can_read, can_send FROM inbox_visibility
                 WHERE inbox_user_aiu_id = 11 ORDER BY inbox_peer_aiu_id'
            )
        );
        $this->assertSame(
            [['can_read' => 1, 'can_send' => 1]],
            $this->allRows('SELECT can_read, can_send FROM inbox_visibility WHERE inbox_user_aiu_id = 10')
        );
    }

    public function testAddRowStoresThePairWithItsTwoBits(): void
    {
        new Visibility($this->pdo)->addRow(20, 11, 1, 0);

        $this->assertSame(
            [['inbox_user_aiu_id' => 20, 'inbox_peer_aiu_id' => 11, 'can_read' => 1, 'can_send' => 0]],
            $this->allRows('SELECT * FROM inbox_visibility WHERE inbox_user_aiu_id = 20')
        );
    }
}
