-- Lookup table driving the status dropdown.
-- is_terminal = 1 means "hide from default list" + "block transition while timers are running".
-- is_default  = 1 picks which status new issues get; exactly one row should have it.
-- New statuses can be added later with a plain INSERT.

CREATE TABLE issue_statuses (
    status_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug           VARCHAR(32) NOT NULL UNIQUE,
    label          VARCHAR(64) NOT NULL,
    sort_order     INT         NOT NULL DEFAULT 0,
    is_terminal    TINYINT(1)  NOT NULL DEFAULT 0,
    is_default     TINYINT(1)  NOT NULL DEFAULT 0,
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO issue_statuses (slug, label, sort_order, is_terminal, is_default) VALUES
    ('open',        'Open',        10, 0, 1),
    ('in_progress', 'In Progress', 20, 0, 0),
    ('blocked',     'Blocked',     30, 0, 0),
    ('done',        'Done',        40, 1, 0);
