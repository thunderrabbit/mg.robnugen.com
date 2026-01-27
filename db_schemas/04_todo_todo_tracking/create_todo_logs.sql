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
