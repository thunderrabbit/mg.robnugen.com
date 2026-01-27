# Plan for Implementing TODOs

## Database Schema

Based on existing conventions and `ADD_TODOS.md`, here is the proposed SQL for the `todos` table and a supporting `todo_logs` table for tracking history and progress.

```sql
-- Main table for defining tasks, habits, and todos
CREATE TABLE todos (
    todo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    -- Type determination
    -- 'habit': Daily/recurring boolean completion (e.g. "Exercise")
    -- 'multi_frequency': Count-based per day (e.g. "Drink 8 glasses of water")
    -- 'duration': Timer-based (e.g. "Meditate 10 min")
    -- 'todo': One-off task with due date
    -- 'recurring_todo': Weekly/Monthly task (non-daily)
    type ENUM('habit', 'multi_frequency', 'duration', 'todo', 'recurring_todo') NOT NULL,

    -- Configuration
    target_count INT UNSIGNED NULL DEFAULT 1,       -- For multi_frequency (e.g. 8)
    target_duration_seconds INT UNSIGNED NULL,      -- For duration (e.g. 600 for 10min)

    -- Scheduling / Recurrence
    frequency ENUM('daily', 'weekly', 'monthly', 'once') NOT NULL DEFAULT 'once',
    due_date DATETIME(6) NULL,                      -- For 'todo' type
    recurrence_pattern VARCHAR(255) NULL,           -- For complex schedules if needed (e.g. "Monday,Friday")

    -- Status
    is_active TINYINT(1) NOT NULL DEFAULT 1,        -- Soft delete / archive

    -- Timestamps
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    PRIMARY KEY (todo_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log table for tracking daily progress, streaks, and history
CREATE TABLE todo_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    todo_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- The "business date" this log applies to. Important for streaks.
    date_logged DATE NOT NULL,

    -- Progress tracking
    count_completed INT UNSIGNED NOT NULL DEFAULT 0,  -- Current count (e.g. 5/8)
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0, -- Current duration logged

    -- Completion status for this specific day/instance
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_at_utc DATETIME(6) NULL,

    PRIMARY KEY (log_id),
    -- Ensure one log entry per todo per day (for daily habits/recurrence)
    UNIQUE KEY unique_todo_date (todo_id, date_logged),
    FOREIGN KEY (todo_id) REFERENCES todos(todo_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Questions and Clarifications needed

To proceed with the implementation, I need clarification on the following:

1.  **Recurrence Logic**:
    *   For "Weekly/Monthly Tasks", do you need specific days (e.g., "Every Tuesday") or just "Once a week"?
    *   Should we implement a complex recurrence pattern string (like strict cron or iCal RRule), or keep it simple with just a `frequency` column and maybe a `recurrence_day` integer?

    *   **Decision**: Keep it simple.
        *   hh:mm mon,wed,fri Take Out Trash
        *   daily 8X Drink Water
        *   mon,tue,wed,thu,fri 2X Meditate 10 min
        *   sun Bootcamp and Ultimate Frisbee

        Items with a nX multiplier don't need to have specific times of day.

2.  **Timezone Handling**:
    *   The `todo_logs` table uses `date_logged` (DATE). Users in different timezones interpret "Today" differently.
    *   Should the system auto-generate the `todo_logs` row for "today" based on the user's timezone when they request the dashboard? Or is there a nightly cron job? (On-demand is usually better for personal apps).

    *   **Decision**: On-demand is usually better for personal apps.  Trust browser to know what timezone it is. Todo items with hh:mm are in localtime.  When they are logged, record the timezone based on browser at that time.  Hint: prefer localtime in the DB because it's easier to see at a glance when things happened, regardless of where in the world I was.

3.  **Duration Tasks & Existing Activities**:
    *   You have an existing `activity_kai` table and `activity_session_keys`.
    *   Should "Duration Tasks" in the Todo system link to or use the existing `ActivityTracking` system? Or is this a separate lightweight timer just for checking off a box?
    *   If separate, do we need to "record" the session in detail, or just the total time added to the todo for the day?

    *   **Decision**: Sessions in TABLE `activity_session_keys` are a 1-to-1 mapping of an activity to a YouTube style URL.  See file @MULTIPLATE.md for details.  These are for Pro users only, allowing a URL for actual time spent doing something like meditation. Once the session is over, the timer is done.  The session is recorded in the `activity_kai` table. "kai" in Japanese means "occurrence" or "time (countable)". Once the timer has gone past the intended time, the todo item is marked as complete for the day.  The extra time is recorded as "bonus time".

4.  **Streaks**:
    *   Is strict consecutive completion required for a streak? (e.g. Miss one day -> Reset to 0).
    *   Should we store the current streak count on the `todos` table (cache) or calculate it on the fly from `todo_logs`? (Caching is usually more performant for dashboards).

    *   **Decision**: Strict consecutive completion is required for a streak.  Calculate on the fly from `todo_logs`. Later we will have "shields" that can be used to protect streaks from being broken by missed days.  These shields will be earned through various means, such as completing long streaks or participating in special events.

5.  **Historical Data**:
    *   For "One-time Todos" (`type='todo'`), do we need an entry in `todo_logs`? Or can we just use the `is_completed` field in the main `todos` table if they are single-use?
    *   *Suggestion*: Keep one-time todos simple in the main table, and use `todo_logs` mainly for recurring habits/tasks.

    *   **Decision**: To simplify historical queries, include one-time todos in the todo_logs.

6.  **"Multi-frequency" Reset**:
    *   Confirming: These reset to 0 every day? (e.g., Water tracking).

    *   **Decision**: Yes, and no punishment if not finished the previous day.
