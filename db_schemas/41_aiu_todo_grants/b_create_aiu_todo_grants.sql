-- Which todos a limited actor (agent_inbox_user.todos_limited = 1) may touch.
CREATE TABLE IF NOT EXISTS aiu_todo_grants (
    atg_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    aiu_id         INT UNSIGNED NOT NULL,
    todo_id        BIGINT UNSIGNED NOT NULL,
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    UNIQUE KEY uniq_aiu_todo (aiu_id, todo_id),
    CONSTRAINT fk_atg_aiu FOREIGN KEY (aiu_id) REFERENCES agent_inbox_user(aiu_id) ON DELETE CASCADE,
    CONSTRAINT fk_atg_todo FOREIGN KEY (todo_id) REFERENCES todos(todo_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
