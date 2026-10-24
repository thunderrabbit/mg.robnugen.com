-- Rob's keep/archive/delete calls on what is in the account (cleanup, brain
-- #305). Append-only: one row per decision, the current decision for a thing
-- is its latest row, and a thing with no row is undecided. Exactly one of the
-- seven keys is set. References identities, never sweeps, so it survives a
-- rebuild. issue_id is the mg issue doing the work, if any.

CREATE TABLE dh_triage (
    dh_triage_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_domain_id       INT UNSIGNED NULL,
    dh_user_id         INT UNSIGNED NULL,
    dh_database_id     INT UNSIGNED NULL,
    dh_db_user_id      INT UNSIGNED NULL,
    dh_cron_id         INT UNSIGNED NULL,
    dh_objects_user_id INT UNSIGNED NULL,
    dh_bucket_id       INT UNSIGNED NULL,
    decision           ENUM('undecided','keep','archive','delete','deleted') NOT NULL,
    note               TEXT         NULL,
    issue_id           INT UNSIGNED NULL,
    decided_at_utc     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_domain       (dh_domain_id, decided_at_utc),
    INDEX idx_user         (dh_user_id, decided_at_utc),
    INDEX idx_database     (dh_database_id, decided_at_utc),
    INDEX idx_db_user      (dh_db_user_id, decided_at_utc),
    INDEX idx_cron         (dh_cron_id, decided_at_utc),
    INDEX idx_objects_user (dh_objects_user_id, decided_at_utc),
    INDEX idx_bucket       (dh_bucket_id, decided_at_utc),
    CONSTRAINT chk_triage_one_target CHECK (
        (dh_domain_id IS NOT NULL) + (dh_user_id IS NOT NULL) + (dh_database_id IS NOT NULL)
        + (dh_db_user_id IS NOT NULL) + (dh_cron_id IS NOT NULL)
        + (dh_objects_user_id IS NOT NULL) + (dh_bucket_id IS NOT NULL) = 1
    ),
    FOREIGN KEY (dh_domain_id)       REFERENCES dh_domains(dh_domain_id),
    FOREIGN KEY (dh_user_id)         REFERENCES dh_users(dh_user_id),
    FOREIGN KEY (dh_database_id)     REFERENCES dh_databases(dh_database_id),
    FOREIGN KEY (dh_db_user_id)      REFERENCES dh_db_users(dh_db_user_id),
    FOREIGN KEY (dh_cron_id)         REFERENCES dh_crons(dh_cron_id),
    FOREIGN KEY (dh_objects_user_id) REFERENCES dh_objects_users(dh_objects_user_id),
    FOREIGN KEY (dh_bucket_id)       REFERENCES dh_buckets(dh_bucket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
