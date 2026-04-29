-- Threaded discussion scoped to a single issue. Markdown body.

CREATE TABLE issue_comments (
    issue_comment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_id         INT UNSIGNED NOT NULL,
    author_aiu       INT UNSIGNED NOT NULL,
    body             MEDIUMTEXT   NOT NULL,
    created_at_utc   DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc   DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_issue_time (issue_id, created_at_utc),
    FOREIGN KEY (issue_id)   REFERENCES issues(issue_id)         ON DELETE CASCADE,
    FOREIGN KEY (author_aiu) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
