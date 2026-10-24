-- Panel-managed cron jobs. The command and schedule are NOT stored: they sit
-- behind the panel's Edit and can hold secrets. Titles are assumed unique per
-- user; the importer fails on a duplicate. output_emailed_to is one of Rob's
-- own mailboxes, allowed past the importer's secret rule on purpose.

CREATE TABLE dh_crons (
    dh_cron_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_user_id INT UNSIGNED NOT NULL,
    title      VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_user_title (dh_user_id, title),
    FOREIGN KEY (dh_user_id) REFERENCES dh_users(dh_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_cron_states (
    dh_cron_state_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_cron_id        INT UNSIGNED NOT NULL,
    from_sweep_id     INT UNSIGNED NOT NULL,
    until_sweep_id    INT UNSIGNED NULL,
    active            TINYINT(1)   NOT NULL,
    output_emailed_to VARCHAR(255) NULL,
    UNIQUE KEY uq_cron_from (dh_cron_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_cron_id)     REFERENCES dh_crons(dh_cron_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
