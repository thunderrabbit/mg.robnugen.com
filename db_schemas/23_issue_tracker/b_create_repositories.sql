-- Account-scoped git repositories referenced by issue_commits.

CREATE TABLE repositories (
    repo_id        SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    name           VARCHAR(100) NOT NULL,
    url            VARCHAR(500) NULL,
    default_branch VARCHAR(100) NULL DEFAULT 'master',
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at_utc DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uniq_user_repo (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
