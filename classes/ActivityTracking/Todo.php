<?php

namespace ActivityTracking;

class Todo {
    private $pdo;

    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get today's todos for a user, filtered by day of week
     *
     * @param int $user_id
     * @param string $dayOfWeek Day name (Sun, Mon, Tue, Wed, Thu, Fri, Sat)
     * @return array Array of todos with completion status
     */
    /**
     * Get today's todos for a user, filtered by schedule
     *
     * @param int $user_id
     * @param string $dayOfWeek Day name (Sun, Mon, Tue, Wed, Thu, Fri, Sat)
     * @param int $dayOfMonth Day of month (1-31)
     * @param string $todayDate Full date (Y-m-d)
     * @return array Array of todos with completion status
     */
    public function getTodaysTodos(int $user_id, string $dayOfWeek, int $dayOfMonth, string $todayDate): array {
        $stmt = $this->pdo->prepare("
            SELECT
                t.todo_id,
                t.title,
                t.description,
                t.is_timer,
                t.is_counter,
                t.activity_id,
                t.target_count,
                t.target_duration_seconds,
                t.do_time,
                t.due_date,
                t.do_every_n_days,
                a.activity_name
            FROM todos t
            LEFT JOIN activities a ON t.activity_id = a.activity_id
            WHERE t.user_id = ?
            AND t.is_active = 1
            AND (
                (t.do_days IS NOT NULL AND FIND_IN_SET(?, t.do_days) > 0)    -- Weekly match
                OR
                (t.do_dates IS NOT NULL AND FIND_IN_SET(?, t.do_dates) > 0)  -- Monthly match
                OR
                (t.due_date IS NOT NULL AND DATE(t.due_date) = ?)            -- Specific Due Date
                OR
                -- specific date is before today but not 2 weeks old
                (t.due_date IS NOT NULL AND DATE(t.due_date) < ? AND DATE(t.due_date) > DATE_SUB(?, INTERVAL 2 YEAR))
                OR
                (t.do_days IS NULL AND t.do_dates IS NULL AND t.due_date IS NULL AND t.do_every_n_days IS NULL) -- Unscheduled / Anytime
                OR
                (
                    t.do_every_n_days IS NOT NULL
                    AND (
                        NOT EXISTS (
                            SELECT 1 FROM todo_logs tl
                            WHERE tl.todo_id = t.todo_id
                        )
                        OR
                        (
                            SELECT DATEDIFF(?, DATE(MAX(tl.date_logged)))
                            FROM todo_logs tl
                            WHERE tl.todo_id = t.todo_id
                        ) >= t.do_every_n_days
                    )
                )
            )
            ORDER BY t.do_time ASC, t.title ASC
        ");

        $stmt->execute([$user_id, $dayOfWeek, $dayOfMonth, $todayDate, $todayDate, $todayDate, $todayDate]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get completion count for a todo on a specific date
     *
     * @param int $todo_id
     * @param string $date Date in Y-m-d format
     * @return int Number of completions today
     */
    public function getCompletionCount(int $todo_id, string $date): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM todo_logs
            WHERE todo_id = ?
            AND DATE(date_logged) = ?
        ");

        $stmt->execute([$todo_id, $date]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)$result['count'];
    }

    /**
     * Get all completions for a todo on a specific date
     *
     * @param int $todo_id
     * @param string $date Date in Y-m-d format
     * @return array Array of completion records
     */
    public function getCompletionsForDate(int $todo_id, string $date): array {
        $stmt = $this->pdo->prepare("
            SELECT log_id, nth, date_logged, duration_seconds, ak_id
            FROM todo_logs
            WHERE todo_id = ?
            AND DATE(date_logged) = ?
            ORDER BY nth ASC
        ");

        $stmt->execute([$todo_id, $date]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get total completion status for a todo (across all time)
     *
     * @param int $todo_id
     * @return array [count => int, last_logged => string|null]
     */
    public function getCompletionStatus(int $todo_id): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) as count,
                MAX(date_logged) as last_logged
            FROM todo_logs
            WHERE todo_id = ?
        ");

        $stmt->execute([$todo_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'count' => (int)($result['count'] ?? 0),
            'last_logged' => $result['last_logged'] ?? null
        ];
    }

    /**
     * Log a todo completion
     *
     * @param int $todo_id
     * @param int $user_id
     * @param int $nth Which instance (1, 2, 3...)
     * @param string $date_logged Local datetime
     * @param string $timezone Timezone string
     * @param int|null $duration_seconds Duration for timed todos
     * @param int|null $ak_id Activity session ID if linked
     * @return int The new log_id
     */
    public function logCompletion(
        int $todo_id,
        int $user_id,
        int $nth,
        string $date_logged,
        string $timezone,
        ?int $duration_seconds = null,
        ?int $ak_id = null
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO todo_logs (
                todo_id,
                user_id,
                date_logged,
                nth,
                duration_seconds,
                timezone,
                ak_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $todo_id,
            $user_id,
            $date_logged,
            $nth,
            $duration_seconds,
            $timezone,
            $ak_id
        ]);

        $log_id = (int)$this->pdo->lastInsertId();

        // INSERT IGNORE returns 0 for lastInsertId when row was a duplicate
        if ($log_id === 0 && $ak_id !== null) {
            $existing = $this->pdo->prepare("
                SELECT log_id FROM todo_logs
                WHERE todo_id = ? AND ak_id = ?
            ");
            $existing->execute([$todo_id, $ak_id]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);
            return $row ? (int)$row['log_id'] : 0;
        }

        return $log_id;
    }

    /**
     * Remove a todo completion (uncheck)
     *
     * @param int $todo_id
     * @param int $user_id
     * @param int $nth Which instance to remove
     * @param string $date Date in Y-m-d format
     * @return bool Success
     */
    public function removeCompletion(int $todo_id, int $user_id, int $nth, string $date): bool {
        $stmt = $this->pdo->prepare("
            DELETE FROM todo_logs
            WHERE todo_id = ?
            AND user_id = ?
            AND nth = ?
            AND DATE(date_logged) = ?
        ");

        $stmt->execute([$todo_id, $user_id, $nth, $date]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Verify that a todo belongs to a user
     *
     * @param int $todo_id
     * @param int $user_id
     * @return bool
     */
    public function verifyOwnership(int $todo_id, int $user_id): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM todos
            WHERE todo_id = ? AND user_id = ?
        ");

        $stmt->execute([$todo_id, $user_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result['count'] > 0;
    }

    /**
     * Get a single todo by ID
     *
     * @param int $todo_id
     * @return array|null
     */
    public function getTodo(int $todo_id): ?array {
        $stmt = $this->pdo->prepare("
            SELECT
                t.*,
                a.activity_name
            FROM todos t
            LEFT JOIN activities a ON t.activity_id = a.activity_id
            WHERE t.todo_id = ?
        ");

        $stmt->execute([$todo_id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Get all active todos for a user
     *
     * @param int $user_id
     * @return array
     */
    public function getAllTodos(int $user_id): array {
        $stmt = $this->pdo->prepare("
            SELECT
                t.*,
                a.activity_name
            FROM todos t
            LEFT JOIN activities a ON t.activity_id = a.activity_id
            WHERE t.user_id = ?
            AND t.is_active = 1
            ORDER BY t.created_at_utc DESC
        ");

        $stmt->execute([$user_id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a new todo item

     *
     * @param array $data Todo data
     * @return int|false New todo ID or false on failure
     */
    public function createTodo(array $data) {
        // Basic validation
        if (empty($data['user_id']) || empty($data['title'])) {
            return false;
        }

        $fields = [
            'user_id',
            'title',
            'description',
            'is_timer',
            'is_counter',
            'target_count',
            'target_duration_seconds',
            'do_days',
            'do_dates',
            'do_every_n_days',
            'do_time',
            'due_date',
            'activity_id',
            'is_active'
        ];

        $columns = [];
        $placeholders = [];
        $values = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $columns[] = $field;
                $placeholders[] = '?';
                $values[] = $data[$field];
            }
        }

        // Set default is_active to 1 if not provided
        if (!in_array('is_active', $columns)) {
            $columns[] = 'is_active';
            $placeholders[] = '?';
            $values[] = 1;
        }

        $sql = "INSERT INTO todos (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->pdo->prepare($sql);

        if ($stmt->execute($values)) {
            return (int)$this->pdo->lastInsertId();
        }

        return false;
    }

    /**
     * Update an existing todo
     *
     * @param int $todo_id
     * @param array $data Update data
     * @return bool Success
     */
    public function updateTodo(int $todo_id, array $data): bool {
        if (empty($data)) {
            return false;
        }

        $fields = [
            'title',
            'description',
            'is_timer',
            'is_counter',
            'target_count',
            'target_duration_seconds',
            'do_days',
            'do_dates',
            'do_every_n_days',
            'do_time',
            'due_date',
            'activity_id',
            'is_active'
        ];

        $setParts = [];
        $values = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $setParts[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($setParts)) {
            return false;
        }

        $values[] = $todo_id;

        $sql = "UPDATE todos SET " . implode(', ', $setParts) . " WHERE todo_id = ?";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($values);
    }

    /**
     * Delete a todo (soft delete by setting is_active = 0)
     *
     * @param int $todo_id
     * @param int $user_id User ID for ownership verification
     * @return bool Success
     */
    public function deleteTodo(int $todo_id, int $user_id): bool {
        // Verify ownership before deleting
        if (!$this->verifyOwnership($todo_id, $user_id)) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            UPDATE todos
            SET is_active = 0
            WHERE todo_id = ?
        ");

        return $stmt->execute([$todo_id]);
    }


    /**
     * Get completed todo history with pagination
     *
     * @param int $user_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getCompletedHistory(int $user_id, int $limit = 50, int $offset = 0): array {
        $stmt = $this->pdo->prepare("
            SELECT
                tl.*,
                t.title,
                t.description,
                t.is_timer,
                t.target_duration_seconds
            FROM todo_logs tl
            JOIN todos t ON tl.todo_id = t.todo_id
            WHERE tl.user_id = ?
            ORDER BY tl.date_logged DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute([$user_id, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get upcoming todos (tomorrow and beyond)
     *
     * @param int $user_id
     * @param string $todayDate Y-m-d
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getUpcomingTodos(int $user_id, string $todayDate, int $limit = 50, int $offset = 0): array {
        $stmt = $this->pdo->prepare("
            SELECT
                t.*,
                a.activity_name
            FROM todos t
            LEFT JOIN activities a ON t.activity_id = a.activity_id
            WHERE t.user_id = ?
            AND t.is_active = 1
            AND (
                (t.due_date IS NOT NULL AND DATE(t.due_date) > ?)
                OR
                (t.do_dates IS NOT NULL AND t.do_dates > ?)
            )
            ORDER BY
                CASE WHEN t.due_date IS NOT NULL THEN t.due_date ELSE t.do_dates END ASC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute([$user_id, $todayDate, $todayDate, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    /**
     * Get days-between todos with their computed next-due dates
     *
     * @param int $user_id
     * @param string $todayDate Y-m-d
     * @return array Todos with next_due_date computed from last completion
     */
    public function getDaysBetweenTodos(int $user_id, string $todayDate): array {
        $stmt = $this->pdo->prepare("
            SELECT
                t.*,
                a.activity_name,
                (
                    SELECT MAX(DATE(tl.date_logged))
                    FROM todo_logs tl
                    WHERE tl.todo_id = t.todo_id
                ) AS last_completed_date
            FROM todos t
            LEFT JOIN activities a ON t.activity_id = a.activity_id
            WHERE t.user_id = ?
            AND t.is_active = 1
            AND t.do_every_n_days IS NOT NULL
        ");

        $stmt->execute([$user_id]);
        $todos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($todos as &$todo) {
            $n = (int) $todo['do_every_n_days'];
            if ($todo['last_completed_date']) {
                $todo['next_due_date'] = date('Y-m-d', strtotime($todo['last_completed_date'] . " + {$n} days"));
            } else {
                $todo['next_due_date'] = $todayDate;
            }
        }

        return $todos;
    }

    /**
     * Get fully completed todo history (where nth >= target_count)
     *
     * @param int $user_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getFullyCompletedHistory(int $user_id, int $limit = 50, int $offset = 0): array {
        $stmt = $this->pdo->prepare("
            SELECT
                tl.log_id,
                tl.todo_id,
                tl.date_logged,
                tl.timezone,
                tl.duration_seconds,
                tl.ak_id,
                t.title,
                t.target_count,
                t.target_duration_seconds,
                t.is_timer,
                ak.activity_id,
                ak.bonus_sec,
                ak.actual_sec,
                a.activity_name
            FROM todo_logs tl
            JOIN todos t ON tl.todo_id = t.todo_id
            LEFT JOIN activity_kai ak ON tl.ak_id = ak.ak_id
            LEFT JOIN activities a ON ak.activity_id = a.activity_id
            WHERE tl.user_id = ?
            AND tl.nth >= t.target_count
            ORDER BY tl.date_logged DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute([$user_id, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
