<?php

declare(strict_types=1);

namespace Tests\Unit;

use ActivityTracking\ActivityKai;
use Billing\Credits;
use Codeception\Test\Unit;

/**
 * The queries the v1 sessions and stats endpoints (wwwroot/api/v1/) and the
 * billing and register pages run through ActivityTracking\ActivityKai and
 * Billing\Credits.
 *
 * Most run on an in-memory SQLite, as TimerListQueriesTest does. Three use
 * TIMESTAMPDIFF with UTC_TIMESTAMP(), or DATE_SUB with INTERVAL, which SQLite
 * cannot run: those are pinned, comparing the SQL text (whitespace collapsed
 * on both sides) and the parameters, and say so in their names.
 *
 * User 1 has sessions 1 (activity 1, 2026-06-10, done), 2 (activity 2,
 * 2026-06-11, done), 3 (activity 1, 2026-06-12, still running); user 2 has
 * session 4 (activity 1, 2026-06-11, done).
 */
class SessionStatsCreditQueriesTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE activities (activity_id INTEGER PRIMARY KEY, activity_name TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE timezones (timezone_id INTEGER PRIMARY KEY, iana_name TEXT NOT NULL)");
        $this->pdo->exec(
            "CREATE TABLE activity_kai (
                ak_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                activity_id INTEGER NOT NULL,
                timezone_id INTEGER NOT NULL,
                start_local_dt TEXT NOT NULL,
                intended_sec INTEGER NOT NULL,
                actual_sec INTEGER NULL,
                bonus_sec INTEGER NOT NULL DEFAULT 0
            )"
        );
        $this->pdo->exec("CREATE TABLE api_credits (user_id INTEGER PRIMARY KEY, credits_remaining INTEGER NOT NULL)");
        $this->pdo->exec("INSERT INTO activities (activity_id, activity_name) VALUES (1, 'Sit'), (2, 'Walk')");
        $this->pdo->exec("INSERT INTO timezones (timezone_id, iana_name) VALUES (5, 'Asia/Tokyo')");
        $this->pdo->exec(
            "INSERT INTO activity_kai
                (ak_id, user_id, activity_id, timezone_id, start_local_dt, intended_sec, actual_sec, bonus_sec) VALUES
                (1, 1, 1, 5, '2026-06-10 07:00:00', 600, 610, 10),
                (2, 1, 2, 5, '2026-06-11 07:00:00', 300, 290, 0),
                (3, 1, 1, 5, '2026-06-12 07:00:00', 600, NULL, 0),
                (4, 2, 1, 5, '2026-06-11 08:00:00', 900, 900, 0)"
        );
        $this->pdo->exec("INSERT INTO api_credits (user_id, credits_remaining) VALUES (1, 42)");
    }

    /**
     * A PDO that accepts one prepare() of exactly this SQL and one execute()
     * of exactly these parameters, compared by type as well as value, and
     * answers fetch() and fetchAll() with what is given.
     *
     * @param list<mixed> $params
     * @param array<string, mixed>|false $row
     * @param list<mixed> $all
     */
    private function pdoExpecting(string $sql, array $params, array|false $row = false, array $all = []): \PDO
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with($this->identicalTo($params))->willReturn(true);
        $stmt->method('fetch')->willReturn($row);
        $stmt->method('fetchAll')->willReturn($all);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('prepare')->with($this->callback(
            static fn (string $given): bool => trim((string) preg_replace('/\s+/', ' ', $given)) === $sql
        ))->willReturn($stmt);
        return $pdo;
    }

    /**
     * @return list<mixed>
     */
    private function ids(?string $from, ?string $to, ?int $activity_id, ?int $is_active, int $limit = 20, int $offset = 0): array
    {
        $sessions = new ActivityKai($this->pdo)->getFilteredSessions(1, $from, $to, $activity_id, $is_active, $limit, $offset);
        return array_column($sessions, 'ak_id');
    }

    public function testFilteredSessionsAreTheUsersOwnNewestFirstWithNamesJoined(): void
    {
        $sessions = new ActivityKai($this->pdo)->getFilteredSessions(1, null, null, null, null, 20, 0);

        $this->assertSame([3, 2, 1], array_column($sessions, 'ak_id'));
        $this->assertSame(
            [
                'ak_id', 'activity_id', 'activity_name', 'start_local_dt', 'intended_sec', 'actual_sec', 'bonus_sec',
                'timezone', 'is_active',
            ],
            array_keys($sessions[0])
        );
        $this->assertSame(['Sit', 'Asia/Tokyo', 1], [$sessions[0]['activity_name'], $sessions[0]['timezone'], $sessions[0]['is_active']]);
        $this->assertSame(['Walk', 0], [$sessions[1]['activity_name'], $sessions[1]['is_active']]);
    }

    public function testFilteredSessionsByDateRangeActivityAndState(): void
    {
        $this->assertSame([3, 2], $this->ids('2026-06-11', null, null, null));
        $this->assertSame([2, 1], $this->ids(null, '2026-06-11', null, null));
        $this->assertSame([2], $this->ids('2026-06-11', '2026-06-11', null, null));
        $this->assertSame([3, 1], $this->ids(null, null, 1, null));
        $this->assertSame([3], $this->ids(null, null, null, 1));
        $this->assertSame([2, 1], $this->ids(null, null, null, 0));
        $this->assertSame([3, 2, 1], $this->ids(null, null, null, 7));
    }

    public function testFilteredSessionsPage(): void
    {
        $this->assertSame([2], $this->ids(null, null, null, null, 1, 1));
    }

    public function testGetSessionWithElapsedIsPinned(): void
    {
        $sql = 'SELECT ak.ak_id, ak.activity_id, a.activity_name,'
            . ' ak.start_local_dt, ak.intended_sec, ak.actual_sec, ak.bonus_sec,'
            . ' t.iana_name AS timezone,'
            . ' CASE WHEN ak.actual_sec IS NULL THEN 1 ELSE 0 END AS is_active,'
            . ' CASE WHEN ak.actual_sec IS NULL'
            . ' THEN TIMESTAMPDIFF(SECOND, ak.created_at_utc, UTC_TIMESTAMP())'
            . ' ELSE NULL END AS elapsed_sec'
            . ' FROM activity_kai ak'
            . ' JOIN activities a ON ak.activity_id = a.activity_id'
            . ' JOIN timezones t ON ak.timezone_id = t.timezone_id'
            . ' WHERE ak.ak_id = ? AND ak.user_id = ?';
        $row = ['ak_id' => 3, 'elapsed_sec' => 12];

        $this->assertSame($row, new ActivityKai($this->pdoExpecting($sql, [3, 1], $row))->getSessionWithElapsed(3, 1));
        $this->assertNull(new ActivityKai($this->pdoExpecting($sql, [3, 2], false))->getSessionWithElapsed(3, 2));
    }

    public function testElapsedSecOfIsPinnedAndNullForASessionThatIsNotRunning(): void
    {
        $sql = 'SELECT TIMESTAMPDIFF(SECOND, created_at_utc, UTC_TIMESTAMP()) AS elapsed'
            . ' FROM activity_kai'
            . ' WHERE ak_id = ? AND user_id = ? AND actual_sec IS NULL';

        $this->assertSame(95, new ActivityKai($this->pdoExpecting($sql, [3, 1], ['elapsed' => 95]))->elapsedSecOf(3, 1));
        $this->assertNull(new ActivityKai($this->pdoExpecting($sql, [1, 1], false))->elapsedSecOf(1, 1));
    }

    public function testGetTotalsCountsOnlyTheUsersFinishedSessions(): void
    {
        $kai = new ActivityKai($this->pdo);

        $this->assertSame(['total_sessions' => 2, 'total_seconds' => 900], $kai->getTotals(1));
        $this->assertSame(['total_sessions' => 0, 'total_seconds' => 0], $kai->getTotals(9));
    }

    public function testGetRecentSessionDatesIsPinned(): void
    {
        $sql = 'SELECT DISTINCT DATE(start_local_dt) AS d'
            . ' FROM activity_kai'
            . ' WHERE user_id = ? AND actual_sec IS NOT NULL'
            . ' AND start_local_dt >= DATE_SUB(NOW(), INTERVAL 90 DAY)'
            . ' ORDER BY d DESC';
        $dates = ['2026-06-11', '2026-06-10'];

        $this->assertSame(
            $dates,
            new ActivityKai($this->pdoExpecting($sql, [1], false, $dates))->getRecentSessionDates(1)
        );
    }

    public function testGetRemainingIsZeroForAUserWithNoCreditRow(): void
    {
        $credits = new Credits($this->pdo);

        $this->assertSame(42, $credits->getRemaining(1));
        $this->assertSame(0, $credits->getRemaining(2));
    }

    public function testAddTrialGivesANewUserOneHundredCredits(): void
    {
        $credits = new Credits($this->pdo);

        $credits->addTrial(2);

        $this->assertSame(100, $credits->getRemaining(2));
        $this->assertSame(42, $credits->getRemaining(1));
    }
}
