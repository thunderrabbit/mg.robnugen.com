-- Individual commit hashes linked to an issue. A "range" is represented as two rows
-- with `note` tagging them (e.g. "range_start" / "range_end"), keeping queries trivial.
-- API-layer invariant: repositories.user_id must match the issue's project.user_id.

CREATE TABLE issue_commits (
    issue_commit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_id        INT UNSIGNED NOT NULL,
    repo_id         SMALLINT UNSIGNED NOT NULL,
    commit_hash     VARCHAR(64)  NOT NULL,
    note            VARCHAR(255) NULL,
    added_by_aiu    INT UNSIGNED NOT NULL,
    created_at_utc  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_issue (issue_id),
    INDEX idx_hash  (commit_hash),
    FOREIGN KEY (issue_id)     REFERENCES issues(issue_id)         ON DELETE CASCADE,
    FOREIGN KEY (repo_id)      REFERENCES repositories(repo_id),
    FOREIGN KEY (added_by_aiu) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
