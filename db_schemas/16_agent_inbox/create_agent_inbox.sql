CREATE TABLE IF NOT EXISTS agent_inbox (
    message_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    message      TEXT         NOT NULL,
    priority     ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    seen_at      DATETIME     DEFAULT NULL,
    done_at      DATETIME     DEFAULT NULL,
    response     TEXT         DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_pending (user_id, done_at, priority, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
