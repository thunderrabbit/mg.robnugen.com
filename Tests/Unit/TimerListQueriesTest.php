<?php

declare(strict_types=1);

namespace Tests\Unit;

use ActivityTracking\Activity;
use ActivityTracking\ActivityKai;
use ActivityTracking\Todo;
use Codeception\Test\Unit;

/**
 * The queries the timer and todo list endpoints (wwwroot/api/list-*.php,
 * wwwroot/api/todos/list.php) run through the ActivityTracking gateways, over
 * an in-memory SQLite (no server, gone with the process), as
 * ExtermItemsPagesTest does.
 *
 * User 1 has two running sessions (ak 1 and 2) and three stopped ones (ak 3,
 * 4 and 5). User 2 has one of each (ak 6 running, ak 7 stopped). Todo 50
 * (user 1) wants one completion a day, todo 51 (user 1) wants three, and todo
 * 60 belongs to user 2.
 */
class TimerListQueriesTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE activities (
                activity_id INTEGER PRIMARY KEY,
                activity_name TEXT NOT NULL,
                description TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                type TEXT NOT NULL DEFAULT 'FREE',
                user_id INTEGER
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE timezones (
                timezone_id INTEGER PRIMARY KEY,
                iana_name TEXT NOT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE activity_kai (
                ak_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                activity_id INTEGER NOT NULL,
                start_local_dt TEXT NOT NULL,
                intended_sec INTEGER NOT NULL,
                actual_sec INTEGER,
                bonus_sec INTEGER NOT NULL DEFAULT 0,
                timezone_id INTEGER NOT NULL,
                created_at_utc TEXT NOT NULL,
                updated_at_utc TEXT NOT NULL
            )"
        );
        $this->pdo->exec("CREATE TABLE activity_session_keys (session_key TEXT PRIMARY KEY, ak_id INTEGER)");
        $this->pdo->exec(
            "CREATE TABLE todos (
                todo_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                target_count INTEGER DEFAULT 1
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE todo_logs (
                log_id INTEGER PRIMARY KEY,
                todo_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                date_logged TEXT NOT NULL,
                nth INTEGER NOT NULL DEFAULT 1
            )"
        );
        $this->pdo->exec(
            "INSERT INTO activities (activity_id, activity_name, description, is_active, type, user_id) VALUES
                (1, 'Meditation', 'Meditation practice', 1, 'FREE', NULL),
                (2, 'Work', 'Professional work', 1, 'PUBLIC', NULL),
                (3, 'Breathing', NULL, 1, 'FREE', NULL),
                (4, 'Retired', NULL, 0, 'FREE', NULL),
                (5, 'Secret', NULL, 1, 'PRIVATE', 1)"
        );
        $this->pdo->exec("INSERT INTO timezones (timezone_id, iana_name) VALUES (1, 'Asia/Tokyo'), (2, 'Australia/Adelaide')");
        $this->pdo->exec(
            "INSERT INTO activity_kai
                (ak_id, user_id, activity_id, start_local_dt, intended_sec, actual_sec, bonus_sec, timezone_id,
                 created_at_utc, updated_at_utc) VALUES
                (1, 1, 1, '2026-11-01 06:00:00', 600, NULL, 0, 1, '2026-10-31 21:00:00', '2026-10-31 21:00:00'),
                (2, 1, 2, '2026-11-01 09:00:00', 1500, NULL, 0, 2, '2026-10-31 22:30:00', '2026-10-31 22:30:00'),
                (3, 1, 1, '2026-10-28 06:00:00', 600, 600, 0, 1, '2026-10-27 21:00:00', '2026-10-27 21:10:00'),
                (4, 1, 1, '2026-10-29 06:00:00', 600, 660, 60, 1, '2026-10-28 21:00:00', '2026-10-28 21:11:00'),
                (5, 1, 2, '2026-10-30 09:00:00', 1500, 900, 0, 2, '2026-10-29 22:30:00', '2026-10-29 22:45:00'),
                (6, 2, 1, '2026-11-01 07:00:00', 300, NULL, 0, 1, '2026-10-31 22:00:00', '2026-10-31 22:00:00'),
                (7, 2, 1, '2026-10-30 07:00:00', 300, 300, 0, 1, '2026-10-29 22:00:00', '2026-10-29 22:05:00')"
        );
        $this->pdo->exec("INSERT INTO activity_session_keys (session_key, ak_id) VALUES ('aaaaaaaaaaa', 2), ('ccccccccccc', 4)");
        $this->pdo->exec(
            "INSERT INTO todos (todo_id, user_id, title, target_count) VALUES
                (50, 1, 'Sit', 1),
                (51, 1, 'Water', 3),
                (52, 1, 'Never done', 1),
                (60, 2, 'Other', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO todo_logs (log_id, todo_id, user_id, date_logged, nth) VALUES
                (1, 50, 1, '2026-10-30 06:10:00', 1),
                (2, 50, 1, '2026-11-01 06:10:00', 1),
                (3, 51, 1, '2026-11-01 08:00:00', 1),
                (4, 51, 1, '2026-11-01 12:00:00', 2),
                (5, 51, 1, '2026-11-01 18:00:00', 3),
                (6, 60, 2, '2026-11-02 07:05:00', 1)"
        );
    }

    public function testActiveSessionsAreTheUsersRunningOnesNewestFirst(): void
    {
        $sessions = new ActivityKai($this->pdo)->getActiveSessions(1);

        $this->assertSame([2, 1], array_map(\Field::toInt(...), array_column($sessions, 'ak_id')));
        $this->assertSame(
            [
                'session_key' => 'aaaaaaaaaaa',
                'ak_id' => 2,
                'activity_id' => 2,
                'activity_name' => 'Work',
                'start_local_dt' => '2026-11-01 09:00:00',
                'intended_sec' => 1500,
                'timezone_id' => 2,
                'timezone_iana' => 'Australia/Adelaide',
            ],
            $sessions[0]
        );
    }

    public function testAnActiveSessionWithoutAKeyStillComesBack(): void
    {
        $sessions = new ActivityKai($this->pdo)->getActiveSessions(1);

        $this->assertNull($sessions[1]['session_key']);
    }

    public function testNoActiveSessionsIsAnEmptyList(): void
    {
        $this->assertSame([], new ActivityKai($this->pdo)->getActiveSessions(3));
    }

    public function testCompletedCountIsTheUsersStoppedSessions(): void
    {
        $kai = new ActivityKai($this->pdo);

        $this->assertSame(3, $kai->getCompletedCount(1));
        $this->assertSame(1, $kai->getCompletedCount(2));
        $this->assertSame(0, $kai->getCompletedCount(3));
    }

    public function testCompletedSessionsAreMostRecentlyChangedFirst(): void
    {
        $sessions = new ActivityKai($this->pdo)->getCompletedSessions(1, 10, 0);

        $this->assertSame([5, 4, 3], array_map(\Field::toInt(...), array_column($sessions, 'ak_id')));
        $this->assertSame(
            [
                'session_key' => 'ccccccccccc',
                'ak_id' => 4,
                'activity_id' => 1,
                'activity_name' => 'Meditation',
                'start_local_dt' => '2026-10-29 06:00:00',
                'intended_sec' => 600,
                'actual_sec' => 660,
                'bonus_sec' => 60,
                'timezone_id' => 1,
                'timezone_iana' => 'Asia/Tokyo',
                'updated_at_utc' => '2026-10-28 21:11:00',
            ],
            $sessions[1]
        );
    }

    public function testCompletedSessionsTakeALimitAndAnOffset(): void
    {
        $kai = new ActivityKai($this->pdo);

        $this->assertSame([5, 4], array_map(\Field::toInt(...), array_column($kai->getCompletedSessions(1, 2, 0), 'ak_id')));
        $this->assertSame([3], array_map(\Field::toInt(...), array_column($kai->getCompletedSessions(1, 2, 2), 'ak_id')));
        $this->assertSame([], $kai->getCompletedSessions(1, 2, 4));
    }

    public function testFreeActivitiesAreTheActiveFreeOnesByName(): void
    {
        $this->assertSame(
            [
                ['activity_id' => 3, 'activity_name' => 'Breathing', 'description' => null],
                ['activity_id' => 1, 'activity_name' => 'Meditation', 'description' => 'Meditation practice'],
            ],
            new Activity($this->pdo)->getFreeActivities()
        );
    }

    public function testFullyCompletedCountIsTheLogsThatReachedTheTarget(): void
    {
        $todo = new Todo($this->pdo);

        // User 1: both logs of todo 50 (target 1) and the third of todo 51 (target 3).
        $this->assertSame(3, $todo->getFullyCompletedCount(1));
        $this->assertSame(1, $todo->getFullyCompletedCount(2));
        $this->assertSame(0, $todo->getFullyCompletedCount(3));
    }

    public function testLastDoneDateIsTheDayOfTheNewestLog(): void
    {
        $todo = new Todo($this->pdo);

        $this->assertSame('2026-11-01', $todo->getLastDoneDate(50));
        $this->assertSame('2026-11-02', $todo->getLastDoneDate(60));
    }

    public function testLastDoneDateIsNullForATodoNeverLogged(): void
    {
        $this->assertNull(new Todo($this->pdo)->getLastDoneDate(52));
    }
}
