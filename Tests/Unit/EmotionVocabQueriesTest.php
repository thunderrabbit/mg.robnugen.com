<?php

declare(strict_types=1);

namespace Tests\Unit;

use Admin\OmgAlerts;
use Codeception\Test\Unit;
use Emotional\Vocab;

/**
 * The queries the emotion vocab endpoints (wwwroot/api/v1/_emotions.php) run
 * through Emotional\Vocab and Admin\OmgAlerts, over an in-memory SQLite (no
 * server, gone with the process), as TimerListQueriesTest does.
 *
 * Key 7 has two states (handles 111 and 222, rows 1 and 2) and key 8 has one
 * (handle 111 again, row 3): a handle is unique per key, not across keys.
 */
class EmotionVocabQueriesTest extends Unit
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
            "CREATE TABLE omg_rob_this_happened (
                omg_id INTEGER PRIMARY KEY,
                context TEXT NOT NULL,
                message TEXT NOT NULL,
                acknowledged_at TEXT
            )"
        );
        $this->pdo->exec(
            "INSERT INTO my_ids_for_my_users_state (mifmus_id, api_key_id, my_id, state) VALUES
                (1, 7, 111, 'cipher-calm'),
                (2, 7, 222, 'cipher-curious'),
                (3, 8, 111, 'cipher-other')"
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private function allRows(string $table): array
    {
        $stmt = $this->pdo->query("SELECT * FROM $table ORDER BY 1");
        $this->assertNotFalse($stmt);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testAddEntryStoresTheStateUnderTheKeysHandle(): void
    {
        new Vocab($this->pdo)->addEntry(7, 333, 'cipher-new');

        $this->assertSame(
            ['mifmus_id' => 4, 'api_key_id' => 7, 'my_id' => 333, 'state' => 'cipher-new'],
            $this->allRows('my_ids_for_my_users_state')[3]
        );
    }

    public function testAddEntryThrowsCode23000ForAHandleTheKeyAlreadyUses(): void
    {
        try {
            new Vocab($this->pdo)->addEntry(7, 111, 'cipher-again');
            $this->fail('A handle the key already uses must throw.');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        $this->assertCount(3, $this->allRows('my_ids_for_my_users_state'));
    }

    public function testGetEntriesAreOnlyTheKeysOwn(): void
    {
        $entries = new Vocab($this->pdo)->getEntries(7);

        $this->assertSame(
            [
                ['my_id' => 111, 'state' => 'cipher-calm'],
                ['my_id' => 222, 'state' => 'cipher-curious'],
            ],
            $entries
        );
        $this->assertSame([], new Vocab($this->pdo)->getEntries(99));
    }

    public function testUpdateStateChangesOnlyThatKeysHandle(): void
    {
        $this->assertTrue(new Vocab($this->pdo)->updateState(7, 111, 'cipher-changed'));

        $this->assertSame(
            ['cipher-changed', 'cipher-curious', 'cipher-other'],
            array_column($this->allRows('my_ids_for_my_users_state'), 'state')
        );
    }

    public function testUpdateStateIsFalseForAHandleTheKeyDoesNotHave(): void
    {
        $this->assertFalse(new Vocab($this->pdo)->updateState(8, 222, 'cipher-changed'));

        $this->assertSame(
            ['cipher-calm', 'cipher-curious', 'cipher-other'],
            array_column($this->allRows('my_ids_for_my_users_state'), 'state')
        );
    }

    public function testMifmusIdOfIsTheRowBehindTheKeysHandle(): void
    {
        $vocab = new Vocab($this->pdo);

        $this->assertSame(1, $vocab->mifmusIdOf(7, 111));
        $this->assertSame(3, $vocab->mifmusIdOf(8, 111));
        $this->assertNull($vocab->mifmusIdOf(8, 222));
    }

    public function testDeleteEntryRemovesOneRow(): void
    {
        new Vocab($this->pdo)->deleteEntry(1);

        $this->assertSame([2, 3], array_column($this->allRows('my_ids_for_my_users_state'), 'mifmus_id'));
    }

    public function testGetCountIsTheKeysOwn(): void
    {
        $vocab = new Vocab($this->pdo);

        $this->assertSame(2, $vocab->getCount(7));
        $this->assertSame(1, $vocab->getCount(8));
        $this->assertSame(0, $vocab->getCount(99));
    }

    public function testDeleteAllRemovesOnlyTheKeysStates(): void
    {
        new Vocab($this->pdo)->deleteAll(7);

        $this->assertSame([3], array_column($this->allRows('my_ids_for_my_users_state'), 'mifmus_id'));
    }

    public function testOmgAlertsAddStoresAnUnreadAlert(): void
    {
        new OmgAlerts($this->pdo)->add('emotional/vocab', 'something remarkable');

        $this->assertSame(
            [['omg_id' => 1, 'context' => 'emotional/vocab', 'message' => 'something remarkable', 'acknowledged_at' => null]],
            $this->allRows('omg_rob_this_happened')
        );
    }
}
