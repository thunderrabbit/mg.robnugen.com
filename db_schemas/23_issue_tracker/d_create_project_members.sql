-- Which aiu's can read and/or write inside each project.
-- Mirrors the bit-flag shape used by inbox_visibility.

CREATE TABLE project_members (
    project_id  INT UNSIGNED NOT NULL,
    member_aiu  INT UNSIGNED NOT NULL,
    can_read    TINYINT(1)   NOT NULL DEFAULT 1,
    can_write   TINYINT(1)   NOT NULL DEFAULT 0,
    granted_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uniq_project_member (project_id, member_aiu),
    KEY idx_member (member_aiu),
    FOREIGN KEY (project_id) REFERENCES projects(project_id)      ON DELETE CASCADE,
    FOREIGN KEY (member_aiu) REFERENCES agent_inbox_user(aiu_id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
