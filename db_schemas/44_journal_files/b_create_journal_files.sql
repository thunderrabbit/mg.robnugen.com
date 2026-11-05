-- Recent journal entries mirrored from Lemur 13, so Rob can edit them on mg.
--
-- base_*   what Lemur 13 last uploaded for this path. base_hash is how
--          Lemur 13 tells, before writing an edit, whether its own copy
--          changed since: if so it keeps its copy and saves the edit beside it.
-- edit_*   Rob's saved edit, waiting for Lemur 13. edit_path differs from
--          path only when he renamed a file git has never tracked.
-- status   synced        nothing waiting
--          edit_waiting  Rob saved; Lemur 13 has not fetched it yet
--          conflict      changed in both places; neither version was lost
--          refused       Lemur 13's own check refused the edit (status_note says why)
CREATE TABLE IF NOT EXISTS journal_files (
    jf_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    path            VARCHAR(255) NOT NULL,
    base_content    MEDIUMTEXT NOT NULL,
    base_hash       CHAR(64) NOT NULL,
    git_state       ENUM('untracked', 'modified', 'committed') NOT NULL,
    uploaded_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    edit_content    MEDIUMTEXT NULL,
    edit_path       VARCHAR(255) NULL,
    edited_at_utc   DATETIME(6) NULL,
    status          ENUM('synced', 'edit_waiting', 'conflict', 'refused') NOT NULL DEFAULT 'synced',
    status_note     VARCHAR(1000) NULL,

    UNIQUE KEY uniq_user_path (user_id, path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- When Lemur 13 last finished a sync run, for the "last synced" line.
CREATE TABLE IF NOT EXISTS journal_sync_runs (
    jsr_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    synced_at_utc   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    UNIQUE KEY uniq_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
