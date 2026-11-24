<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\Actors;
use Codeception\Test\Unit;
use Inbox\Messages;
use Inbox\OwnerFilter;

/**
 * The queries the inbox page (wwwroot/inbox/index.php) runs through
 * Inbox\Messages and Auth\Actors, over an in-memory SQLite given NOW()
 * (fixed at 2026-06-15 12:00:00) and FIELD(), as InboxMessageQueriesTest
 * does. Every one of them runs there.
 *
 * The page is the account owner's own: its queries are scoped by account
 * alone, with no test of which actor is asking.
 *
 * Account 1 has actors 10 (Rob, human, may broadcast), 11 (Helper, the
 * oldest) and 12 (Bells). Its messages: 1 from 10 to 11 (high), 2 from 11 to
 * 10 (normal, seen), 3 from 10 to everyone (low), 4 from 12 to 10 (normal,
 * done), 5 from 10 to 11 (normal, archived), 6 from 10 to 11 (normal, shown
 * from 2026-07-01). Message 7 is account 2's. Created one minute apart in id
 * order, except 3, which is the oldest.
 *
 * The actor table here has no created_at_utc column until the one test that
 * needs it adds it. The page's list orders by an unqualified created_at_utc
 * while joining the actor table, which has one too: MySQL takes the one in
 * the select list, and SQLite calls it ambiguous. The SQL is the page's own,
 * so the stand-in table gives way, not the query.
 */
class InboxOwnerPageQueriesTest extends Unit
{
    private const string NOW = '2026-06-15 12:00:00';

    private \Pdo\Sqlite $pdo;

    protected function _before(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->createFunction('NOW', static fn (): string => self::NOW, 0);
        $this->pdo->createFunction(
            'FIELD',
            static function (mixed $needle, mixed ...$list): int {
                $at = array_search($needle, array_values($list), true);
                return $at === false ? 0 : $at + 1;
            },
            -1
        );
        $this->pdo->exec(
            "CREATE TABLE agent_inbox_user (
                aiu_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                actor_type TEXT NOT NULL DEFAULT 'agent',
                can_broadcast_inbox INTEGER NOT NULL DEFAULT 0
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE agent_inbox (
                message_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                sender_aiu INTEGER NULL,
                recipient_aiu INTEGER NULL,
                message TEXT NOT NULL,
                priority TEXT NOT NULL DEFAULT 'normal',
                sender_timezone TEXT NULL,
                show_date TEXT NULL,
                seen_at TEXT NULL,
                done_at TEXT NULL,
                archived_at TEXT NULL,
                response TEXT NULL,
                created_at_utc TEXT NOT NULL DEFAULT '2026-06-15 11:00:00',
                updated_at_utc TEXT NOT NULL DEFAULT '2026-06-15 11:00:00'
            )"
        );
        $this->pdo->exec(
            "INSERT INTO agent_inbox_user (aiu_id, user_id, name, actor_type, can_broadcast_inbox) VALUES
                (10, 1, 'Rob', 'human', 1),
                (11, 1, 'Helper', 'agent', 0),
                (12, 1, 'Bells', 'agent', 0),
                (20, 2, 'Other', 'human', 0),
                (21, 2, 'Otherbot', 'agent', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO agent_inbox
                (message_id, user_id, sender_aiu, recipient_aiu, message, priority, show_date,
                 seen_at, done_at, archived_at, created_at_utc) VALUES
                (1, 1, 10, 11, 'm1', 'high', NULL, NULL, NULL, NULL, '2026-06-10 10:01:00'),
                (2, 1, 11, 10, 'm2', 'normal', NULL, '2026-06-11 00:00:00', NULL, NULL, '2026-06-10 10:02:00'),
                (3, 1, 10, NULL, 'm3', 'low', NULL, NULL, NULL, NULL, '2026-06-10 10:00:00'),
                (4, 1, 12, 10, 'm4', 'normal', NULL, '2026-06-11 00:00:00', '2026-06-12 00:00:00', NULL, '2026-06-10 10:04:00'),
                (5, 1, 10, 11, 'm5', 'normal', NULL, NULL, NULL, '2026-06-12 00:00:00', '2026-06-10 10:05:00'),
                (6, 1, 10, 11, 'm6', 'normal', '2026-07-01 00:00:00', NULL, NULL, NULL, '2026-06-10 10:06:00'),
                (7, 2, 20, 20, 'm7', 'normal', NULL, NULL, NULL, NULL, '2026-06-10 10:07:00')"
        );
    }

    /**
     * @return array<mixed>
     */
    private function message(int $message_id): array
    {
        $stmt = $this->pdo->query("SELECT * FROM agent_inbox WHERE message_id = $message_id");
        $this->assertNotFalse($stmt);
        $rows = \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    /**
     * The ids account 1's owner sees with this filter, by id ascending
     * unless a sort is given, after checking the count agrees.
     *
     * @return list<mixed>
     */
    private function ids(OwnerFilter $filter, string $sort_by = 'id', bool $ascending = true): array
    {
        $messages = new Messages($this->pdo);
        $rows = $messages->getPageForOwner(1, $filter, $sort_by, $ascending, 50, 0);
        $this->assertSame(count($rows), $messages->getCountForOwner(1, $filter));
        return array_column($rows, 'message_id');
    }

    private function everything(): OwnerFilter
    {
        return new OwnerFilter(show_archived: true, show_future: true);
    }

    public function testTheOwnerSeesTheWholeAccountWithTheNamesJoined(): void
    {
        $rows = new Messages($this->pdo)->getPageForOwner(1, $this->everything(), 'id', true, 50, 0);

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($rows, 'message_id'));
        $this->assertSame(
            [
                'message_id', 'message', 'priority', 'show_date', 'sender_timezone', 'sender_aiu', 'recipient_aiu',
                'seen_at', 'done_at', 'archived_at', 'response', 'created_at_utc', 'updated_at_utc',
                'sender_name', 'recipient_name',
            ],
            array_keys($rows[0])
        );
        $this->assertSame(['Rob', 'Helper'], [$rows[0]['sender_name'], $rows[0]['recipient_name']]);
        $this->assertNull($rows[2]['recipient_name']);
    }

    public function testArchivedAndFutureMessagesAreHiddenByDefault(): void
    {
        $this->assertSame([1, 2, 3, 4], $this->ids(new OwnerFilter()));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids(new OwnerFilter(show_archived: true)));
        $this->assertSame([1, 2, 3, 4, 6], $this->ids(new OwnerFilter(show_future: true)));
    }

    public function testThePriorityFilterTakesOnlyTheThreePriorities(): void
    {
        $this->assertSame([1], $this->ids(new OwnerFilter(true, true, priority: 'high')));
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->ids(new OwnerFilter(true, true, priority: 'urgent')));
    }

    public function testEachStatusLeavesArchivedOutExceptArchived(): void
    {
        $this->assertSame([1, 3, 6], $this->ids(new OwnerFilter(true, true, status: 'pending')));
        $this->assertSame([2], $this->ids(new OwnerFilter(true, true, status: 'seen')));
        $this->assertSame([4], $this->ids(new OwnerFilter(true, true, status: 'done')));
        $this->assertSame([5], $this->ids(new OwnerFilter(true, true, status: 'archived')));
    }

    public function testTheArchivedStatusShowsArchivedEvenWhenTheyAreHidden(): void
    {
        $this->assertSame([5], $this->ids(new OwnerFilter(show_archived: false, show_future: true, status: 'archived')));
    }

    public function testTheToFilter(): void
    {
        $this->assertSame([2, 4], $this->ids(new OwnerFilter(true, true, to: 'me', me_aiu: 10)));
        $this->assertSame([], $this->ids(new OwnerFilter(true, true, to: 'me', me_aiu: null)));
        $this->assertSame([3], $this->ids(new OwnerFilter(true, true, to: 'broadcast')));
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->ids(new OwnerFilter(true, true, to: 'anyone', me_aiu: 10)));
    }

    public function testTheFromFilter(): void
    {
        $this->assertSame([2], $this->ids(new OwnerFilter(true, true, from_aiu: 11)));
    }

    public function testFiltersCombine(): void
    {
        $this->assertSame(
            [1],
            $this->ids(new OwnerFilter(false, false, priority: 'high', status: 'pending', from_aiu: 10))
        );
    }

    public function testEachSort(): void
    {
        $all = $this->everything();

        $this->assertSame([6, 5, 4, 3, 2, 1], $this->ids($all, 'id', false));
        $this->assertSame([3, 1, 2, 4, 5, 6], $this->ids($all, 'date', true));
        $this->assertSame([6, 5, 4, 2, 1, 3], $this->ids($all, 'date', false));
        // priority: high first when descending, low first when ascending; newest first within one.
        $this->assertSame([1, 6, 5, 4, 2, 3], $this->ids($all, 'priority', false));
        $this->assertSame([3, 6, 5, 4, 2, 1], $this->ids($all, 'priority', true));
        // status: unarchived, then not done, then unseen, newest first within each.
        $this->assertSame([6, 1, 3, 2, 4, 5], $this->ids($all, 'status', false));
        // anything else: unarchived, then not done, then by priority, newest first.
        $this->assertSame([1, 6, 2, 3, 4, 5], $this->ids($all, 'no such sort', false));
    }

    public function testLimitAndOffsetPageTheList(): void
    {
        $rows = new Messages($this->pdo)->getPageForOwner(1, $this->everything(), 'id', true, 2, 2);

        $this->assertSame([3, 4], array_column($rows, 'message_id'));
        $this->assertSame(6, new Messages($this->pdo)->getCountForOwner(1, $this->everything()));
    }

    public function testAddMessageTakesASenderlessMessage(): void
    {
        $message_id = new Messages($this->pdo)->addMessage(1, 'no human actor', 'normal', null, null, null, 11);

        $this->assertNull($this->message($message_id)['sender_aiu']);
    }

    public function testHasRecipientIsForAMessageOfTheAccountAddressedToOneActor(): void
    {
        $messages = new Messages($this->pdo);

        $this->assertTrue($messages->hasRecipient(1, 1));
        $this->assertFalse($messages->hasRecipient(3, 1));
        $this->assertFalse($messages->hasRecipient(7, 1));
        $this->assertFalse($messages->hasRecipient(99, 1));
    }

    public function testUpdateForOwnerWritesAllFourFieldsInTheAccountOnly(): void
    {
        $messages = new Messages($this->pdo);

        $messages->updateForOwner(2, 1, 'm2b', 'high', 12, '2026-09-01');
        $row = $this->message(2);
        $this->assertSame(['m2b', 'high', 12, '2026-09-01'], [$row['message'], $row['priority'], $row['recipient_aiu'], $row['show_date']]);

        $messages->updateForOwner(2, 1, 'm2c', 'low', null, null);
        $row = $this->message(2);
        $this->assertSame(['m2c', 'low', null, null], [$row['message'], $row['priority'], $row['recipient_aiu'], $row['show_date']]);

        $messages->updateForOwner(7, 1, 'reached across', 'high', null, null);
        $this->assertSame('m7', $this->message(7)['message']);
    }

    public function testArchiveForOwnerSetsTheTimeOnceInTheAccountOnly(): void
    {
        $messages = new Messages($this->pdo);

        $messages->archiveForOwner(1, 1);
        $this->assertSame(self::NOW, $this->message(1)['archived_at']);

        $messages->archiveForOwner(5, 1);
        $this->assertSame('2026-06-12 00:00:00', $this->message(5)['archived_at']);

        $messages->archiveForOwner(7, 1);
        $this->assertNull($this->message(7)['archived_at']);
    }

    public function testDeleteForOwnerRemovesTheMessageInTheAccountOnly(): void
    {
        $messages = new Messages($this->pdo);

        $messages->deleteForOwner(7, 1);
        $messages->deleteForOwner(2, 1);

        $this->assertSame('m7', $this->message(7)['message']);
        $this->assertSame([1, 3, 4, 5, 6], $this->ids($this->everything()));
    }

    public function testGetHumanActorIsTheAccountsHumanWithItsBroadcastBit(): void
    {
        $actors = new Actors($this->pdo);

        $this->assertSame(['aiu_id' => 10, 'can_broadcast_inbox' => 1], $actors->getHumanActor(1));
        $this->assertSame(['aiu_id' => 20, 'can_broadcast_inbox' => 0], $actors->getHumanActor(2));
        $this->assertNull($actors->getHumanActor(3));
    }

    public function testGetActorsOldestFirstAreTheAccountsOwn(): void
    {
        $this->pdo->exec("ALTER TABLE agent_inbox_user ADD COLUMN created_at_utc TEXT");
        $this->pdo->exec(
            "UPDATE agent_inbox_user SET created_at_utc = CASE aiu_id
                WHEN 11 THEN '2026-01-01 00:00:00' WHEN 10 THEN '2026-02-01 00:00:00' ELSE '2026-03-01 00:00:00' END"
        );

        $this->assertSame(
            [
                ['aiu_id' => 11, 'name' => 'Helper'],
                ['aiu_id' => 10, 'name' => 'Rob'],
                ['aiu_id' => 12, 'name' => 'Bells'],
            ],
            new Actors($this->pdo)->getActorsOldestFirst(1)
        );
    }
}
