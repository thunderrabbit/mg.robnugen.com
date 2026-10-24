-- DreamHost inventory (mg #775): read-only snapshots of Rob's hosting account.
-- Rob only; no API reads it. Design: ~/work/rob/dreamhost-inventory/SCHEMA.md.
--
-- Every thing is ONE identity row for life; what changes about it is a state
-- row spanning sweeps [from_sweep_id, until_sweep_id). until_sweep_id is the
-- first sweep the state no longer held in; NULL = it still holds in the latest
-- sweep. Values that change at every sweep (disk, size) are *_usage series.
-- Nothing outside the dh_ layer-1 tables may reference dh_sweep_id, so a
-- rebuild (empty the states, replay every sweep in date order) loses nothing.
--
-- No secrets are stored. Natural keys use utf8mb4_bin: MySQL and bucket names
-- are case-sensitive, and the importer lower-cases domain names.

CREATE TABLE dh_accounts (
    dh_account_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    panel_account_id VARCHAR(16)  NOT NULL,
    account_name     VARCHAR(64)  NOT NULL,
    UNIQUE KEY uq_panel_account (panel_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- 'reconstructed' = built by hand or from clues, not observed in the panel.
CREATE TABLE dh_sweeps (
    dh_sweep_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_account_id   INT UNSIGNED NOT NULL,
    swept_on        DATE         NOT NULL,
    provenance      ENUM('swept','reconstructed') NOT NULL DEFAULT 'swept',
    swept_at_utc    DATETIME(6)  NOT NULL,
    source_dir      VARCHAR(255) NOT NULL,
    notes           TEXT         NULL,
    imported_at_utc DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_account_swept_on (dh_account_id, swept_on),
    FOREIGN KEY (dh_account_id) REFERENCES dh_accounts(dh_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
