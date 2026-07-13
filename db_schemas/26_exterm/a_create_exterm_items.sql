-- exterm_items: per-project context docs and tasks shared between agents (Exterminal).
-- author_aiu is always set by the server from the API key — callers cannot forge provenance.
-- kind='context' items treat status/risk as no-ops; kind='task' enforces the status lifecycle.
-- done_at_utc is stamped by the server when status transitions to done, approved, or rejected.
-- Irreversible tasks must pass through needs_approval → approved before agents may act.

CREATE TABLE exterm_items (
    exterm_item_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id      INT UNSIGNED NOT NULL,
    author_aiu      INT UNSIGNED NOT NULL,
    assignee_aiu    INT UNSIGNED NULL,
    kind            ENUM('context','task') NOT NULL DEFAULT 'task',
    status          ENUM('open','in_progress','needs_approval','approved','rejected','done')
                    NOT NULL DEFAULT 'open',
    risk            ENUM('reversible','irreversible') NOT NULL DEFAULT 'reversible',
    title           VARCHAR(255) NOT NULL,
    body            MEDIUMTEXT NULL,
    created_at_utc  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                    ON UPDATE CURRENT_TIMESTAMP(6),
    done_at_utc     DATETIME(6) NULL,
    PRIMARY KEY (exterm_item_id),
    INDEX idx_exterm_project_status  (project_id, status),
    INDEX idx_exterm_assignee_status (assignee_aiu, status),
    CONSTRAINT fk_exterm_project  FOREIGN KEY (project_id)   REFERENCES projects(project_id)        ON DELETE CASCADE,
    CONSTRAINT fk_exterm_author   FOREIGN KEY (author_aiu)   REFERENCES agent_inbox_user(aiu_id),
    CONSTRAINT fk_exterm_assignee FOREIGN KEY (assignee_aiu) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
