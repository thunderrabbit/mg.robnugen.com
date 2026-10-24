-- Shell/SFTP users. shell and home are not stored: shell follows from access
-- (ssh -> bash, sftp -> /usr/lib/sftp-server) and home is /home/<username>;
-- the importer fails if either stops being true.
-- "No domain attached" = no open dh_domain_states row names the user.

CREATE TABLE dh_users (
    dh_user_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_account_id INT UNSIGNED NOT NULL,
    username      VARCHAR(64)  NOT NULL,
    UNIQUE KEY uq_account_username (dh_account_id, username),
    FOREIGN KEY (dh_account_id) REFERENCES dh_accounts(dh_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_user_states (
    dh_user_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_user_id       INT UNSIGNED NOT NULL,
    from_sweep_id    INT UNSIGNED NOT NULL,
    until_sweep_id   INT UNSIGNED NULL,
    access           ENUM('ssh','sftp','ftp') NOT NULL,
    UNIQUE KEY uq_user_from (dh_user_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_user_id)     REFERENCES dh_users(dh_user_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_user_usage (
    dh_user_usage_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_user_id       INT UNSIGNED    NOT NULL,
    dh_sweep_id      INT UNSIGNED    NOT NULL,
    disk_bytes       BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_user_sweep (dh_user_id, dh_sweep_id),
    FOREIGN KEY (dh_user_id)  REFERENCES dh_users(dh_user_id),
    FOREIGN KEY (dh_sweep_id) REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Evidence of how old a user is (the panel doesn't say), gathered by read-only
-- ssh into the home directory. A user's age is the earliest existed_by.
CREATE TABLE dh_user_age_clues (
    dh_user_age_clue_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_user_id          INT UNSIGNED NOT NULL,
    clue_kind           VARCHAR(32)  NOT NULL,
    existed_by          DATE         NOT NULL,
    collected_at_utc    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    note                TEXT         NULL,
    UNIQUE KEY uq_user_clue (dh_user_id, clue_kind, existed_by),
    FOREIGN KEY (dh_user_id) REFERENCES dh_users(dh_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
