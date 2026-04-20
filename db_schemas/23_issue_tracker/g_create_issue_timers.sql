-- Count-up-only timers, 1 issue : N timers. Deliberately separate from `sessions`
-- (which toggle todo completion on roll-over). Multiple concurrent running timers
-- per worker_aiu are allowed: e.g. a long batch loop while other work is timed.
-- DATETIME(6) so sub-second fixes read like "0.00023 seconds".

CREATE TABLE issue_timers (
    issue_timer_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_id        INT UNSIGNED NOT NULL,
    worker_aiu      INT UNSIGNED NOT NULL,
    started_at_utc  DATETIME(6)  NOT NULL,
    stopped_at_utc  DATETIME(6)  NULL,
    note            VARCHAR(255) NULL,
    INDEX idx_issue           (issue_id),
    INDEX idx_worker_running  (worker_aiu, stopped_at_utc),
    FOREIGN KEY (issue_id)   REFERENCES issues(issue_id)         ON DELETE CASCADE,
    FOREIGN KEY (worker_aiu) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
