<?php

declare(strict_types=1);

namespace ActivityTracking;

class ActivityKai
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Start a new activity session
     *
     * @param int $user_id User ID
     * @param int $activity_id Activity type (1=meditation, 2=sleeping)
     * @param int|null $todo_id nullable linked todo ID
     * @param int $intended_sec Intended duration in seconds
     * @param int $timezone_id Timezone ID from timezones table
     * @param string $start_local_dt Local datetime in format 'Y-m-d H:i:s'
     * @return int The new ak_id
     */
    public function startActivity(
        int $user_id,
        int $activity_id,
        ?int $todo_id,
        int $intended_sec,
        int $timezone_id,
        string $start_local_dt
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO activity_kai (
                user_id,
                activity_id,
                todo_id,
                start_local_dt,
                intended_sec,
                timezone_id,
                created_at_utc,
                updated_at_utc
            ) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");

        $stmt->execute([
            $user_id,
            $activity_id,
            $todo_id,
            $start_local_dt,
            $intended_sec,
            $timezone_id
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Process active sessions with expired timers (auto-complete linked todos)
     * Does NOT stop the activity session itself.
     *
     * @param int $user_id
     * @return int Number of todos auto-completed
     */
    public function processExpiredTimers(int $user_id): int
    {
        // Find active sessions that have passed their intended duration
        // We use a safe buffer of e.g. 5 seconds to avoid race conditions with frontend stop
        $stmt = $this->pdo->prepare("
            SELECT ak_id, todo_id, intended_sec, start_local_dt, timezone_id
            FROM activity_kai
            WHERE user_id = ?
            AND actual_sec IS NULL
            AND todo_id IS NOT NULL
            AND DATE_ADD(created_at_utc, INTERVAL (intended_sec + 5) SECOND) < UTC_TIMESTAMP()
        ");

        $stmt->execute([$user_id]);
        $expiredSessions = \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));

        if (empty($expiredSessions)) {
            return 0;
        }

        $count = 0;
        $todoHelper = new Todo($this->pdo);

        foreach ($expiredSessions as $session) {
            // Check if already completed to avoid duplicates
            // We only want ONE todo completion per activity session
            $checkStmt = $this->pdo->prepare("
                SELECT 1 FROM todo_logs WHERE ak_id = ?
            ");
            $checkStmt->execute([\Database\RowValue::int($session, 'ak_id')]);

            if ($checkStmt->fetchColumn()) {
                continue; // Already processed
            }

            // Fetch timezone string
            $tzStmt = $this->pdo->prepare("SELECT iana_name FROM timezones WHERE timezone_id = ?");
            $tzStmt->execute([\Database\RowValue::int($session, 'timezone_id')]);
            $tz_name = $tzStmt->fetchColumn();
            $timezone = is_string($tz_name) && $tz_name !== '' ? $tz_name : 'UTC';

            // Mark todo as complete
            $todo_id = \Database\RowValue::int($session, 'todo_id');

            // Log date calculation
            $date_logged = date('Y-m-d H:i:s');
            $today = date('Y-m-d');
            try {
                $dt = new \DateTime('now', new \DateTimeZone($timezone));
                $today = $dt->format('Y-m-d');
                $date_logged = $dt->format('Y-m-d H:i:s');
            } catch (\Exception) {
                // Fallback
            }

            $nth = $todoHelper->getCompletionCount($todo_id, $today) + 1;

            $todoHelper->logCompletion(
                $todo_id,
                $user_id,
                $nth,
                $date_logged,
                $timezone,
                \Database\RowValue::int($session, 'intended_sec'),
                \Database\RowValue::int($session, 'ak_id')
            );

            $count++;
        }

        return $count;
    }

    /**
     * Stop an activity session
     *
     * @param int $ak_id Activity session ID
     * @param int $user_id User ID (for security check)
     * @param int $actual_sec Total elapsed time in seconds
     * @param int $bonus_sec Bonus time beyond countdown
     * @return bool Success
     */
    public function stopActivity(
        int $ak_id,
        int $user_id,
        int $actual_sec,
        int $bonus_sec
    ): bool {
        $stmt = $this->pdo->prepare("
            UPDATE activity_kai
            SET actual_sec = ?,
                bonus_sec = ?,
                updated_at_utc = UTC_TIMESTAMP()
            WHERE ak_id = ?
            AND user_id = ?
        ");

        $stmt->execute([
            $actual_sec,
            $bonus_sec,
            $ak_id,
            $user_id
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get active session for a user and activity
     * (For Pro users with multiple concurrent timers)
     *
     * @param int $user_id
     * @param int $activity_id
     * @return array<mixed>|null Session data or null if none active
     */
    public function getActiveSession(int $user_id, int $activity_id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT ak_id, start_local_dt, intended_sec, timezone_id
            FROM activity_kai
            WHERE user_id = ?
            AND activity_id = ?
            AND actual_sec IS NULL
            ORDER BY created_at_utc DESC
            LIMIT 1
        ");

        $stmt->execute([$user_id, $activity_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($result) ? $result : null;
    }

    /**
     * Get user's recent sessions
     *
     * @param int $user_id
     * @param int $limit Number of sessions to retrieve
     * @return list<array<mixed>> Array of session records
     */
    public function getUserSessions(int $user_id, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ak.ak_id,
                ak.activity_id,
                a.activity_name,
                ak.start_local_dt,
                ak.intended_sec,
                ak.actual_sec,
                ak.bonus_sec,
                t.iana_name as timezone,
                t.display_name as timezone_display
            FROM activity_kai ak
            JOIN activities a ON ak.activity_id = a.activity_id
            JOIN timezones t ON ak.timezone_id = t.timezone_id
            WHERE ak.user_id = ?
            ORDER BY ak.start_local_dt DESC
            LIMIT ?
        ");

        $stmt->execute([$user_id, $limit]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Verify that an activity session belongs to a user
     *
     * @param int $ak_id
     * @param int $user_id
     * @return bool
     */
    public function verifyOwnership(int $ak_id, int $user_id): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM activity_kai
            WHERE ak_id = ? AND user_id = ?
        ");

        $stmt->execute([$ak_id, $user_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return \Field::int($result, 'count') > 0;
    }

    /**
     * The activity a user's session belongs to.
     *
     * @param int $ak_id Activity session ID
     * @param int $user_id User ID (for security check)
     * @return int|null activity_id, or null if the session is missing or not the user's
     */
    public function activityIdOf(int $ak_id, int $user_id): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT activity_id
            FROM activity_kai
            WHERE ak_id = ? AND user_id = ?
        ");

        $stmt->execute([$ak_id, $user_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($result) ? \Field::int($result, 'activity_id') : null;
    }

    /**
     * Find an existing session with the same user, activity, start and duration
     *
     * Lets a retried backfill return the first session instead of a duplicate.
     *
     * @param int $user_id User ID
     * @param int $activity_id Activity ID
     * @param string $start_local_dt Local start time, Y-m-d H:i:s
     * @param int $actual_sec Duration in seconds
     * @return int|null ak_id of the matching session, or null
     */
    public function findBackfill(int $user_id, int $activity_id, string $start_local_dt, int $actual_sec): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT ak_id
            FROM activity_kai
            WHERE user_id = ? AND activity_id = ? AND start_local_dt = ? AND actual_sec = ?
            ORDER BY ak_id
            LIMIT 1
        ");

        $stmt->execute([$user_id, $activity_id, $start_local_dt, $actual_sec]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($result) ? \Field::int($result, 'ak_id') : null;
    }

    /**
     * Delete an activity session
     *
     * @param int $ak_id Activity session ID
     * @param int $user_id User ID (for security check)
     * @return bool Success
     */
    public function deleteActivity(int $ak_id, int $user_id): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM activity_kai
            WHERE ak_id = ? AND user_id = ?
        ");

        $stmt->execute([$ak_id, $user_id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Every session of a user that has not been stopped, newest first, each
     * with its activity name, timezone and session key.
     *
     * @return list<array<mixed>>
     */
    public function getActiveSessions(int $user_id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ask.session_key,
                ak.ak_id,
                ak.activity_id,
                a.activity_name,
                ak.start_local_dt,
                ak.intended_sec,
                ak.timezone_id,
                t.iana_name as timezone_iana
            FROM activity_kai ak
            JOIN activities a ON ak.activity_id = a.activity_id
            JOIN timezones t ON ak.timezone_id = t.timezone_id
            LEFT JOIN activity_session_keys ask ON ak.ak_id = ask.ak_id
            WHERE ak.user_id = ?
              AND ak.actual_sec IS NULL
            ORDER BY ak.created_at_utc DESC
        ");

        $stmt->execute([$user_id]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * How many sessions a user has stopped.
     */
    public function getCompletedCount(int $user_id): int
    {
        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*) as total
            FROM activity_kai ak
            WHERE ak.user_id = ?
              AND ak.actual_sec IS NOT NULL
        ");
        $countStmt->execute([$user_id]);
        return \Field::int($countStmt->fetch(\PDO::FETCH_ASSOC), 'total');
    }

    /**
     * One page of a user's stopped sessions, most recently changed first,
     * each with its activity name, timezone and session key.
     *
     * @return list<array<mixed>>
     */
    public function getCompletedSessions(int $user_id, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ask.session_key,
                ak.ak_id,
                ak.activity_id,
                a.activity_name,
                ak.start_local_dt,
                ak.intended_sec,
                ak.actual_sec,
                ak.bonus_sec,
                ak.timezone_id,
                t.iana_name as timezone_iana,
                ak.updated_at_utc
            FROM activity_kai ak
            JOIN activities a ON ak.activity_id = a.activity_id
            JOIN timezones t ON ak.timezone_id = t.timezone_id
            LEFT JOIN activity_session_keys ask ON ak.ak_id = ask.ak_id
            WHERE ak.user_id = ?
              AND ak.actual_sec IS NOT NULL
            ORDER BY ak.updated_at_utc DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute([$user_id, $limit, $offset]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * One page of a user's sessions, newest first, with the activity's name
     * and the timezone's. Each filter that is not null narrows the list:
     * `$from` and `$to` bound the local start date (inclusive), and
     * `$is_active` 1 keeps the running ones, 0 the finished ones; any other
     * number keeps both.
     *
     * @return list<array<mixed>>
     */
    public function getFilteredSessions(
        int $user_id,
        ?string $from,
        ?string $to,
        ?int $activity_id,
        ?int $is_active,
        int $limit,
        int $offset
    ): array {
        $where  = ['ak.user_id = ?'];
        $params = [$user_id];

        if ($from !== null) {
            $where[]  = 'DATE(ak.start_local_dt) >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[]  = 'DATE(ak.start_local_dt) <= ?';
            $params[] = $to;
        }
        if ($activity_id !== null) {
            $where[]  = 'ak.activity_id = ?';
            $params[] = $activity_id;
        }
        if ($is_active === 1) {
            $where[]  = 'ak.actual_sec IS NULL';
        } elseif ($is_active === 0) {
            $where[]  = 'ak.actual_sec IS NOT NULL';
        }

        $where_sql = implode(' AND ', $where);
        $params[]  = $limit;
        $params[]  = $offset;

        $stmt = $this->pdo->prepare("
            SELECT ak.ak_id, ak.activity_id, a.activity_name,
                   ak.start_local_dt, ak.intended_sec, ak.actual_sec, ak.bonus_sec,
                   t.iana_name AS timezone,
                   CASE WHEN ak.actual_sec IS NULL THEN 1 ELSE 0 END AS is_active
            FROM activity_kai ak
            JOIN activities a  ON ak.activity_id  = a.activity_id
            JOIN timezones  t  ON ak.timezone_id  = t.timezone_id
            WHERE {$where_sql}
            ORDER BY ak.start_local_dt DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * One of a user's sessions with the activity's name, the timezone's and,
     * while it is running, how many seconds it has run (elapsed_sec, null
     * once finished). Null when the user has no such session.
     *
     * @return array<string, mixed>|null
     */
    public function getSessionWithElapsed(int $ak_id, int $user_id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT ak.ak_id, ak.activity_id, a.activity_name,
                   ak.start_local_dt, ak.intended_sec, ak.actual_sec, ak.bonus_sec,
                   t.iana_name AS timezone,
                   CASE WHEN ak.actual_sec IS NULL THEN 1 ELSE 0 END AS is_active,
                   CASE WHEN ak.actual_sec IS NULL
                        THEN TIMESTAMPDIFF(SECOND, ak.created_at_utc, UTC_TIMESTAMP())
                        ELSE NULL END AS elapsed_sec
            FROM activity_kai ak
            JOIN activities a ON ak.activity_id = a.activity_id
            JOIN timezones  t ON ak.timezone_id  = t.timezone_id
            WHERE ak.ak_id = ? AND ak.user_id = ?
        ");
        $stmt->execute([$ak_id, $user_id]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($session)) {
            return null;
        }
        /** @var array<string, mixed> $session */
        return $session;
    }

    /**
     * How many seconds one of a user's running sessions has run, read in one
     * query so it also proves the session is still running. Null when the
     * user has no such session or it has stopped.
     */
    public function elapsedSecOf(int $ak_id, int $user_id): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, created_at_utc, UTC_TIMESTAMP()) AS elapsed
            FROM activity_kai
            WHERE ak_id = ? AND user_id = ? AND actual_sec IS NULL
        ");
        $stmt->execute([$ak_id, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? \Field::int($row, 'elapsed') : null;
    }

    /**
     * How many sessions a user has finished and their total length, as
     * total_sessions and total_seconds.
     *
     * @return array<string, mixed>
     */
    public function getTotals(int $user_id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*)             AS total_sessions,
                COALESCE(SUM(actual_sec), 0) AS total_seconds
            FROM activity_kai
            WHERE user_id = ? AND actual_sec IS NOT NULL
        ");
        $stmt->execute([$user_id]);
        $totals = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($totals)) {
            // An aggregate with no GROUP BY always gives one row.
            return [];
        }
        /** @var array<string, mixed> $totals */
        return $totals;
    }

    /**
     * The distinct local dates, newest first, on which a user finished a
     * session in the last 90 days: enough for any streak.
     *
     * @return list<mixed>
     */
    public function getRecentSessionDates(int $user_id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT DATE(start_local_dt) AS d
            FROM activity_kai
            WHERE user_id = ? AND actual_sec IS NOT NULL
              AND start_local_dt >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY d DESC
        ");
        $stmt->execute([$user_id]);
        return array_values($stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
