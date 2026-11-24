<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\Actors;
use Codeception\Test\Unit;
use Inbox\Messages;
use Inbox\Visibility;

/**
 * The queries the inbox endpoint (wwwroot/api/v1/_inbox.php) runs through
 * Inbox\Messages, Inbox\Visibility and Auth\Actors, over an in-memory SQLite
 * (no server, gone with the process). Every one of them runs there: SQLite
 * is given a NOW() fixed at 2026-06-15 12:00:00 and MySQL's FIELD(), the two
 * functions these queries use that it lacks.
 *
 * Account 1: actor 10 is a supervisor, 11 reads its own inbox and may send
 * to 10, 12 has no rows. Account 2: actor 20.
 *
 * Messages of account 1, by id: 1 from 10 to 11 (high), 2 from 11 to 10
 * (normal, seen), 3 from 10 to everyone (low), 4 from 12 to 10 (normal,
 * done), 5 from 10 to 11 (normal, archived), 6 from 10 to 11 (normal, shown
 * from 2026-07-01, so still in the future). Message 7 is account 2's.
 * Created one minute apart in id order, except 3, which is the oldest.
 */
class InboxMessageQueriesTest extends Unit
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
                description TEXT NULL
            )"
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
            "INSERT INTO agent_inbox_user (aiu_id, user_id, name, description) VALUES
                (10, 1, 'Rob', NULL), (11, 1, 'Helper', 'helps'), (12, 1, 'Bells', NULL), (20, 2, 'Other', NULL)"
        );
        $this->pdo->exec(
            "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send) VALUES
                (10, NULL, 1, 1),
                (11, 11, 1, 0),
                (11, 10, 0, 1)"
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
     * The ids getMessages gives for account 1 as a supervisor would see them,
     * everything included, unless an argument says otherwise.
     *
     * @param list<int>|null $readable
     * @return list<mixed>
     */
    private function ids(
        ?array $readable = null,
        ?int $also_sent_by = null,
        int $sender_aiu = 0,
        ?string $created_after = null,
        ?string $created_before = null,
        bool $include_archived = true,
        bool $include_future = true,
        string $status = '',
        string $order = 'oldest',
        int $limit = 50,
        int $offset = 0,
    ): array {
        $found = new Messages($this->pdo)->getMessages(
            1,
            $readable,
            $also_sent_by,
            $sender_aiu,
            $created_after,
            $created_before,
            $include_archived,
            $include_future,
            $status,
            $order,
            $limit,
            $offset
        );
        return array_column($found['messages'], 'message_id');
    }

    public function testASupervisorSeesTheWholeAccountAndNoOtherAccount(): void
    {
        $found = new Messages($this->pdo)->getMessages(1, null, null, 0, null, null, true, true, '', 'oldest', 50, 0);

        $this->assertSame([3, 1, 2, 4, 5, 6], array_column($found['messages'], 'message_id'));
        $this->assertSame(6, $found['total']);
        $this->assertSame(
            [
                'message_id', 'message', 'priority', 'show_date', 'sender_timezone', 'sender_aiu', 'recipient_aiu',
                'seen_at', 'done_at', 'archived_at', 'response', 'created_at_utc', 'updated_at_utc',
            ],
            array_keys($found['messages'][0])
        );
    }

    public function testAScopedReaderSeesItsReadablePeersAndBroadcasts(): void
    {
        $this->assertSame([3, 1, 5, 6], $this->ids(readable: [11]));
    }

    public function testAReaderWithNoPeersSeesOnlyBroadcasts(): void
    {
        $this->assertSame([3], $this->ids(readable: []));
    }

    public function testIncludeSentAddsWhatTheCallerSent(): void
    {
        $this->assertSame([3, 1, 2, 5, 6], $this->ids(readable: [11], also_sent_by: 11));
        $this->assertSame([3, 4], $this->ids(readable: [], also_sent_by: 12));
    }

    public function testASupervisorIsNotNarrowedByIncludeSent(): void
    {
        $this->assertSame([3, 1, 2, 4, 5, 6], $this->ids(readable: null, also_sent_by: 12));
    }

    public function testTheSenderFilter(): void
    {
        $this->assertSame([2], $this->ids(sender_aiu: 11));
    }

    public function testTheCreatedWindowIsHalfOpen(): void
    {
        $this->assertSame(
            [1, 2],
            $this->ids(created_after: '2026-06-10 10:01:00', created_before: '2026-06-10 10:04:00')
        );
    }

    public function testArchivedAndFutureMessagesAreLeftOutUnlessAsked(): void
    {
        $this->assertSame([3, 1, 2, 4, 6], $this->ids(include_archived: false));
        $this->assertSame([3, 1, 2, 4, 5], $this->ids(include_future: false));
    }

    public function testEachStatus(): void
    {
        $this->assertSame([3, 1, 5, 6], $this->ids(status: 'pending'));
        $this->assertSame([2], $this->ids(status: 'seen'));
        $this->assertSame([4], $this->ids(status: 'done'));
        $this->assertSame([5], $this->ids(status: 'archived'));
        $this->assertSame([3, 1, 2, 4, 5, 6], $this->ids(status: 'anything else'));
    }

    public function testEachOrderAndTheDefault(): void
    {
        $this->assertSame([6, 5, 4, 2, 1, 3], $this->ids(order: 'newest'));
        $this->assertSame([1, 6, 5, 4, 2, 3], $this->ids(order: 'urgent'));
        $this->assertSame([3, 1, 2, 4, 5, 6], $this->ids(order: 'oldest'));
        $this->assertSame([6, 5, 4, 2, 1, 3], $this->ids(order: 'no such order'));
    }

    public function testLimitAndOffsetPageTheListButNotTheTotal(): void
    {
        $found = new Messages($this->pdo)->getMessages(1, [11], null, 0, null, null, true, true, '', 'oldest', 2, 1);

        $this->assertSame([1, 5], array_column($found['messages'], 'message_id'));
        $this->assertSame(4, $found['total']);
    }

    public function testAddMessageStoresItAndReturnsItsId(): void
    {
        $message_id = new Messages($this->pdo)->addMessage(1, 'hello', 'high', '2026-08-01', 'Asia/Tokyo', 11, 10);

        $this->assertSame(8, $message_id);
        $row = $this->message(8);
        $this->assertSame(
            [1, 'hello', 'high', '2026-08-01', 'Asia/Tokyo', 11, 10],
            [
                $row['user_id'], $row['message'], $row['priority'], $row['show_date'],
                $row['sender_timezone'], $row['sender_aiu'], $row['recipient_aiu'],
            ]
        );
    }

    public function testAddMessageKeepsNullsForABroadcastWithNoDateOrZone(): void
    {
        $message_id = new Messages($this->pdo)->addMessage(1, 'to all', 'normal', null, null, 10, null);

        $row = $this->message($message_id);
        $this->assertNull($row['show_date']);
        $this->assertNull($row['sender_timezone']);
        $this->assertNull($row['recipient_aiu']);
    }

    public function testMarkSeenIsForTheSenderTheRecipientOrASupervisor(): void
    {
        $messages = new Messages($this->pdo);

        $this->assertSame(0, $messages->markSeen(1, 1, 12));
        $this->assertNull($this->message(1)['seen_at']);
        $this->assertSame(0, $messages->markSeen(1, 2, 10));

        $this->assertSame(1, $messages->markSeen(1, 1, 11));
        $this->assertSame(self::NOW, $this->message(1)['seen_at']);
        $this->assertSame(0, $messages->markSeen(1, 1, 11));

        $this->assertSame(1, $messages->markSeen(6, 1, 10));
        $this->assertSame(1, $messages->markSeen(5, 1, 10));
    }

    public function testASupervisorMaySeeAMessageItNeitherSentNorReceived(): void
    {
        $this->pdo->exec("UPDATE agent_inbox SET sender_aiu = 12, recipient_aiu = 11 WHERE message_id = 1");

        $this->assertSame(1, new Messages($this->pdo)->markSeen(1, 1, 10));
    }

    public function testMarkDoneSetsBothTimesAndKeepsAnEarlierSeenTime(): void
    {
        $messages = new Messages($this->pdo);

        $this->assertSame(1, $messages->markDone(2, 1, 10, ''));
        $row = $this->message(2);
        $this->assertSame(self::NOW, $row['done_at']);
        $this->assertSame('2026-06-11 00:00:00', $row['seen_at']);
        $this->assertNull($row['response']);

        $this->assertSame(1, $messages->markDone(1, 1, 11, 'did it'));
        $row = $this->message(1);
        $this->assertSame(self::NOW, $row['seen_at']);
        $this->assertSame('did it', $row['response']);
    }

    public function testMarkDoneRefusesAStrangerAndADoneMessage(): void
    {
        $messages = new Messages($this->pdo);

        $this->assertSame(0, $messages->markDone(1, 1, 12, 'not mine'));
        $this->assertNull($this->message(1)['done_at']);
        $this->assertSame(0, $messages->markDone(4, 1, 10, 'again'));
        $this->assertNull($this->message(4)['response']);
    }

    public function testMarkSeenBulkTouchesOnlyTheUnseenOnesTheCallerMayTouch(): void
    {
        // 3 is a broadcast actor 11 neither sent nor may supervise; 2 is seen; 7 is another account's.
        $this->assertSame(3, new Messages($this->pdo)->markSeenBulk([1, 2, 3, 5, 6, 7], 1, 11));

        $this->assertSame(self::NOW, $this->message(1)['seen_at']);
        $this->assertNull($this->message(3)['seen_at']);
        $this->assertSame('2026-06-11 00:00:00', $this->message(2)['seen_at']);
        $this->assertNull($this->message(7)['seen_at']);
    }

    public function testUpdateMessageChangesWhatItIsGivenForTheSenderOnly(): void
    {
        $messages = new Messages($this->pdo);

        $this->assertSame(0, $messages->updateMessage(1, 1, 11, ['message' => 'by the recipient']));
        $this->assertSame('m1', $this->message(1)['message']);

        $this->assertSame(
            1,
            $messages->updateMessage(
                1,
                1,
                10,
                ['message' => 'm1b', 'priority' => 'low', 'show_date' => '2026-09-01', 'recipient_aiu' => 12]
            )
        );
        $row = $this->message(1);
        $this->assertSame(['m1b', 'low', '2026-09-01', 12], [$row['message'], $row['priority'], $row['show_date'], $row['recipient_aiu']]);
    }

    public function testUpdateMessageClearsTheDateAndTheRecipientWithNull(): void
    {
        $this->assertSame(
            1,
            new Messages($this->pdo)->updateMessage(6, 1, 10, ['show_date' => null, 'recipient_aiu' => null])
        );

        $row = $this->message(6);
        $this->assertNull($row['show_date']);
        $this->assertNull($row['recipient_aiu']);
        $this->assertSame('m6', $row['message']);
    }

    public function testAPlainSenderMayEditItsOwnMessage(): void
    {
        // Actor 11 sent message 2 and is no supervisor.
        $this->assertSame(1, new Messages($this->pdo)->updateMessage(2, 1, 11, ['priority' => 'low']));
        $this->assertSame('low', $this->message(2)['priority']);
    }

    public function testNoOneChangesAnotherAccountsMessage(): void
    {
        $messages = new Messages($this->pdo);

        // Actor 10 supervises account 1; message 7 is account 2's.
        $this->assertSame(0, $messages->updateMessage(7, 1, 10, ['message' => 'reached across']));
        $this->assertSame(0, $messages->markDone(7, 1, 10, ''));
        $this->assertSame(0, $messages->archive(7, 1, 10));
        $this->pdo->exec("UPDATE agent_inbox SET seen_at = '2026-06-11 00:00:00' WHERE message_id = 7");
        $this->assertSame(0, $messages->markUnseen(7, 1, 10));

        $row = $this->message(7);
        $this->assertSame('m7', $row['message']);
        $this->assertNull($row['done_at']);
        $this->assertNull($row['archived_at']);
        $this->assertSame('2026-06-11 00:00:00', $row['seen_at']);
    }

    public function testASupervisorMayEditAnothersMessage(): void
    {
        $this->assertSame(1, new Messages($this->pdo)->updateMessage(2, 1, 10, ['priority' => 'high']));
        $this->assertSame('high', $this->message(2)['priority']);
    }

    public function testUpdateMessageRefusesNoChangesAndUnknownColumns(): void
    {
        $messages = new Messages($this->pdo);
        $before = $this->message(1);

        foreach ([[], ['sender_aiu' => 12]] as $changes) {
            try {
                $messages->updateMessage(1, 1, 10, $changes);
                $this->fail('Only message, priority, show_date and recipient_aiu may be changed, and one must be.');
            } catch (\InvalidArgumentException) {
                $this->assertSame($before, $this->message(1));
            }
        }
    }

    public function testArchiveSetsTheTimeOnce(): void
    {
        $messages = new Messages($this->pdo);

        $this->assertSame(0, $messages->archive(1, 1, 12));
        $this->assertSame(1, $messages->archive(1, 1, 11));
        $this->assertSame(self::NOW, $this->message(1)['archived_at']);
        $this->assertSame(0, $messages->archive(1, 1, 11));
    }

    public function testDeleteRemovesOnlyWhatTheCallerMayTouch(): void
    {
        $messages = new Messages($this->pdo);

        $this->assertSame(0, $messages->delete(1, 1, 12));
        $this->assertSame(0, $messages->delete(7, 1, 10));
        $this->assertSame(1, $messages->delete(1, 1, 11));
        $this->assertSame([3, 2, 4, 5, 6], $this->ids());
    }

    public function testMarkUnseenClearsTheTimeUnlessTheMessageIsDone(): void
    {
        $messages = new Messages($this->pdo);

        $this->assertSame(1, $messages->markUnseen(2, 1, 11));
        $this->assertNull($this->message(2)['seen_at']);
        $this->assertSame(0, $messages->markUnseen(4, 1, 10));
        $this->assertSame('2026-06-11 00:00:00', $this->message(4)['seen_at']);
        $this->assertSame(0, $messages->markUnseen(2, 1, 12));
    }

    public function testReadablePeersAreTheCanReadRowsWithNullForASupervisor(): void
    {
        $visibility = new Visibility($this->pdo);

        $this->assertSame([null], $visibility->readablePeers(10));
        $this->assertSame([11], $visibility->readablePeers(11));
        $this->assertSame([], $visibility->readablePeers(12));
    }

    public function testCanSendToNeedsACanSendRowForThatPeerOrForEveryone(): void
    {
        $visibility = new Visibility($this->pdo);

        $this->assertTrue($visibility->canSendTo(11, 10));
        $this->assertFalse($visibility->canSendTo(11, 12));
        $this->assertFalse($visibility->canSendTo(11, 11));
        $this->assertTrue($visibility->canSendTo(10, 12));
        $this->assertFalse($visibility->canSendTo(12, 10));
    }

    public function testGetActorsByNameAreTheAccountsOwnInNameOrder(): void
    {
        $this->assertSame(
            [
                ['aiu_id' => 12, 'name' => 'Bells', 'description' => null],
                ['aiu_id' => 11, 'name' => 'Helper', 'description' => 'helps'],
                ['aiu_id' => 10, 'name' => 'Rob', 'description' => null],
            ],
            new Actors($this->pdo)->getActorsByName(1)
        );
    }
}
