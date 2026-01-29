-- Log table for tracking each completion instance
CREATE TABLE todo_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    todo_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    ak_id BIGINT UNSIGNED NULL,                     -- If set, this todo was completed by this activity_kai
                                                    -- FK to activity_kai(ak_id)

    -- When this instance was completed (local time)
    date_logged DATETIME NOT NULL,                  -- e.g. '2026-01-28 14:30:00'

    -- Which instance is this today? (1st glass of water, 2nd, etc.)
    nth TINYINT UNSIGNED NOT NULL DEFAULT 1,

    -- Duration for timed todos (NULL for simple checkboxes)
    duration_seconds INT UNSIGNED NULL,

    -- Timezone context (captured from browser)
    timezone VARCHAR(64) NULL,                      -- e.g. 'Asia/Tokyo'

    -- Timestamps
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (log_id),
    KEY idx_todo_date (todo_id, date_logged),
    KEY idx_user_date (user_id, date_logged),
    FOREIGN KEY (todo_id) REFERENCES todos(todo_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (ak_id) REFERENCES activity_kai(ak_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
