# Plan for Implementing TODOs

## Database Schema

Based on your feedback, I have revised the schema to be more flexible and integrated with your existing activity system.

### Key Changes
*   **Removed `type` Enum**: Replaced with functional flags and separate scheduling columns.
*   **Flexible Scheduling**: Added `do_days` (Mon-Sun) and `do_dates` (5th, 25th) to handle complex recurrence simply.
*   **Activity Integration**: Added `related_activity_id` to link Todo items to `activity_kai` sessions.
*   **Timezones**: `todo_logs` will store `completed_at_local` and the `timezone` string captured at the time of logging.
*   **Goals**: `target_count` and `target_duration_seconds` can coexist (e.g., "Meditate 10min, 2x per day").

```sql
-- Main table for defining tasks, habits, and todos
CREATE TABLE todos (
    todo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    -- Functional Configuration
    is_timer TINYINT(1) NOT NULL DEFAULT 0,         -- Requires a timer?
    is_counter TINYINT(1) NOT NULL DEFAULT 0,       -- Requires counting? (target_count > 1)

    -- Integration with Activity System
    activity_id BIGINT UNSIGNED NULL,               -- If set, this todo is completed by doing this activity
                                                    -- FK to activities(activity_id)

    -- Goals
    target_count INT UNSIGNED NULL DEFAULT 1,       -- Default 1 (checkbox). >1 for "8 glasses"
    target_duration_seconds INT UNSIGNED NULL,      -- If is_timer=1, this is the goal per session (e.g. 600)

    -- Scheduling / Recurrence
    -- For "Weekly Tasks" (e.g. Mon, Wed, Fri) use do_days
    do_days SET('Sun','Mon','Tue','Wed','Thu','Fri','Sat') NULL,

    -- For "Monthly Tasks" (e.g. 5th and 25th) use do_dates
    do_dates SET('1','2','3','4','5','6','7','8','9','10',
                 '11','12','13','14','15','16','17','18','19','20',
                 '21','22','23','24','25','26','27','28','29','30','31') NULL,

    -- For One-time items (no recurrence set)
    due_date DATETIME NULL,                         -- Single due date

    -- Status
    is_active TINYINT(1) NOT NULL DEFAULT 1,        -- Soft delete / archive

    -- Timestamps
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    PRIMARY KEY (todo_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log table for tracking daily progress, streaks, and history
CREATE TABLE todo_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    todo_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- The "business date" this log applies to (Local Date)
    date_logged DATE NOT NULL,

    -- Progress tracking
    count_completed INT UNSIGNED NOT NULL DEFAULT 0,  -- Current count (e.g. 1/2)
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0, -- Total duration logged for this day

    -- Completion status for this specific day/instance
    is_completed TINYINT(1) NOT NULL DEFAULT 0,

    -- Completion Context
    completed_at_local DATETIME NULL,               -- Local time when the LAST action finished it
    timezone VARCHAR(64) NULL,                      -- e.g. 'Asia/Tokyo' (captured from browser)

    -- Timestamps
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (log_id),
    -- Ensure one log entry per todo per day
    UNIQUE KEY unique_todo_date (todo_id, date_logged),
    FOREIGN KEY (todo_id) REFERENCES todos(todo_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Resolved Questions

*   **Recurrence**: Implemented `do_days` (SET) and `do_dates` (SET) as requested.
*   **Timezones**: `todo_logs` captures `completed_at_local` and `timezone`. App logic will rely on browser/client to determine "today".
*   **Activity Integration**: Uses `related_activity_id`. Logic: When `activity_kai` session finishes, system checks if a Todo exists for that `activity_id` and increments `todo_logs` for "today".
*   **Streaks**: Validated on fly from `todo_logs` (Strict consecutive).
*   **Multi-Frequency**: Resets daily. Logic enforced by `date_logged` unique constraint (new row = new count).

## Next Steps

1.  **Draft Migration**: Create the actual `.sql` file for schema migration.
2.  **Backend Logic**: Plan the PHP classes for `Todo` and `TodoLog` management.
3.  **Frontend**: Update UI to support creating these complex Todo types.
