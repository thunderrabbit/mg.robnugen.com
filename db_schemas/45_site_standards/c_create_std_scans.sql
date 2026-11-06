-- What the scanner on Lemur 13 saw. One std_scans row per run; std_scan_sites
-- says which checkout it looked at, or why it skipped the site (off its trunk,
-- uncommitted changes, no checkout). A skipped site keeps its earlier results.
--
-- std_step_states uses the same span pattern as the sweep tables: a row holds
-- from from_scan_id until (exclusive) until_scan_id, and NULL means it is
-- still true. Only checks the scanner runs are stored; the checks mg answers
-- from its own tables are computed when the page loads. Nothing here
-- references a sweep id.

CREATE TABLE std_scans (
    std_scan_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scanned_at_utc  DATETIME(6) NOT NULL,
    imported_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    steps_commit    CHAR(40) NOT NULL,
    UNIQUE KEY uq_scanned_at (scanned_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE std_scan_sites (
    std_scan_site_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_scan_id      INT UNSIGNED NOT NULL,
    std_site_id      INT UNSIGNED NOT NULL,
    branch           VARCHAR(100) NULL,
    commit_sha       CHAR(40) NULL,
    skipped_reason   VARCHAR(255) NULL,
    UNIQUE KEY uq_scan_site (std_scan_id, std_site_id),
    FOREIGN KEY (std_scan_id) REFERENCES std_scans(std_scan_id),
    FOREIGN KEY (std_site_id) REFERENCES std_sites(std_site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE std_step_states (
    std_step_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_site_id       INT UNSIGNED NOT NULL,
    std_step_id       INT UNSIGNED NOT NULL,
    from_scan_id      INT UNSIGNED NOT NULL,
    until_scan_id     INT UNSIGNED NULL,
    outcome           ENUM('pass','fail','unknown') NOT NULL,
    detail            VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_state_from (std_site_id, std_step_id, from_scan_id),
    KEY idx_open (until_scan_id),
    FOREIGN KEY (std_site_id)   REFERENCES std_sites(std_site_id),
    FOREIGN KEY (std_step_id)   REFERENCES std_steps(std_step_id),
    FOREIGN KEY (from_scan_id)  REFERENCES std_scans(std_scan_id),
    FOREIGN KEY (until_scan_id) REFERENCES std_scans(std_scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
