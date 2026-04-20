-- parent_issue_id is self-FK'd; the "max 2 levels" rule (no grandchildren)
-- is enforced in the API: reject if the prospective parent's own parent_issue_id is non-NULL.
-- assignee_aiu NULL = unassigned queue (Boss Claude picks from these).
-- Transition to an is_terminal status is blocked by the API while any timer on the issue is running.

CREATE TABLE issues (
    issue_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED NOT NULL,
    parent_issue_id INT UNSIGNED NULL,
    author_aiu      INT UNSIGNED NOT NULL,
    assignee_aiu    INT UNSIGNED NULL,
    status_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     MEDIUMTEXT   NULL,
    created_at_utc  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    done_at_utc     DATETIME(6)  NULL,
    INDEX idx_project_status  (project_id, status_id),
    INDEX idx_assignee_status (assignee_aiu, status_id),
    INDEX idx_queue           (project_id, assignee_aiu, status_id),
    INDEX idx_parent          (parent_issue_id),
    FOREIGN KEY (project_id)      REFERENCES projects(project_id)      ON DELETE CASCADE,
    FOREIGN KEY (parent_issue_id) REFERENCES issues(issue_id)          ON DELETE CASCADE,
    FOREIGN KEY (author_aiu)      REFERENCES agent_inbox_user(aiu_id),
    FOREIGN KEY (assignee_aiu)    REFERENCES agent_inbox_user(aiu_id),
    FOREIGN KEY (status_id)       REFERENCES issue_statuses(status_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
