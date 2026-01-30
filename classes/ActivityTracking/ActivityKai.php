<?php

namespace ActivityTracking;

class ActivityKai {
    private $pdo;

    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
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
    public function processExpiredTimers(int $user_id): int {
        // Find active sessions that have passed their intended duration
        // We use a safe buffer of e.g. 1 minute to avoid race conditions with frontend stop
        $stmt = $this->pdo->prepare("
            SELECT ak_id, todo_id, intended_sec, start_local_dt, timezone_id
            FROM activity_kai
            WHERE user_id = ?
            AND actual_sec IS NULL
            AND todo_id IS NOT NULL
            AND DATE_ADD(created_at_utc, INTERVAL (intended_sec + 60) SECOND) < UTC_TIMESTAMP()
        ");

        $stmt->execute([$user_id]);
        $expiredSessions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
            $checkStmt->execute([$session['ak_id']]);

            if ($checkStmt->fetchColumn()) {
                continue; // Already processed
            }

            // Fetch timezone string
            $tzStmt = $this->pdo->prepare("SELECT iana_name FROM timezones WHERE timezone_id = ?");
            $tzStmt->execute([$session['timezone_id']]);
            $timezone = $tzStmt->fetchColumn() ?: 'UTC';

            // Mark todo as complete
            $todo_id = (int)$session['todo_id'];

            // Log date calculation
            $date_logged = date('Y-m-d H:i:s');
            $today = date('Y-m-d');
            try {
                $dt = new \DateTime('now', new \DateTimeZone($timezone));
                $today = $dt->format('Y-m-d');
                $date_logged = $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                // Fallback
            }

            $nth = $todoHelper->getCompletionCount($todo_id, $today) + 1;

            $todoHelper->logCompletion(
                $todo_id,
                $user_id,
                $nth,
                $date_logged,
                $timezone,
                (int)$session['intended_sec'],
                (int)$session['ak_id']
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
     * @return array|null Session data or null if none active
     */
    public function getActiveSession(int $user_id, int $activity_id): ?array {
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

        return $result ?: null;
    }

    /**
     * Get user's recent sessions
     *
     * @param int $user_id
     * @param int $limit Number of sessions to retrieve
     * @return array Array of session records
     */
    public function getUserSessions(int $user_id, int $limit = 10): array {
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
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Verify that an activity session belongs to a user
     *
     * @param int $ak_id
     * @param int $user_id
     * @return bool
     */
    public function verifyOwnership(int $ak_id, int $user_id): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM activity_kai
            WHERE ak_id = ? AND user_id = ?
        ");

        $stmt->execute([$ak_id, $user_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result['count'] > 0;
    }

    /**
     * Delete an activity session
     *
     * @param int $ak_id Activity session ID
     * @param int $user_id User ID (for security check)
     * @return bool Success
     */
    public function deleteActivity(int $ak_id, int $user_id): bool {
        $stmt = $this->pdo->prepare("
            DELETE FROM activity_kai
            WHERE ak_id = ? AND user_id = ?
        ");

        $stmt->execute([$ak_id, $user_id]);

        return $stmt->rowCount() > 0;
    }
}
