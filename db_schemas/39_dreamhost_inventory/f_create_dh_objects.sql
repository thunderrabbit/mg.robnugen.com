-- DreamObjects. key_count is a COUNT; the keys themselves are never stored.
-- bucket_count is not stored: it is derived from the buckets. permissions and
-- size_bytes on buckets are not swept yet (NULL); the bucket state row still
-- records when a bucket appeared and went.

CREATE TABLE dh_objects_users (
    dh_objects_user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_account_id      INT UNSIGNED NOT NULL,
    username           VARCHAR(64)  NOT NULL,
    endpoint           VARCHAR(253) NOT NULL,
    UNIQUE KEY uq_account_username (dh_account_id, username),
    FOREIGN KEY (dh_account_id) REFERENCES dh_accounts(dh_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_objects_user_states (
    dh_objects_user_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_objects_user_id       INT UNSIGNED NOT NULL,
    from_sweep_id            INT UNSIGNED NOT NULL,
    until_sweep_id           INT UNSIGNED NULL,
    key_count                INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_objects_user_from (dh_objects_user_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_objects_user_id) REFERENCES dh_objects_users(dh_objects_user_id),
    FOREIGN KEY (from_sweep_id)      REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id)     REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_buckets (
    dh_bucket_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_objects_user_id INT UNSIGNED NOT NULL,
    name               VARCHAR(63)  NOT NULL,
    UNIQUE KEY uq_objects_user_name (dh_objects_user_id, name),
    FOREIGN KEY (dh_objects_user_id) REFERENCES dh_objects_users(dh_objects_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_bucket_states (
    dh_bucket_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_bucket_id       INT UNSIGNED NOT NULL,
    from_sweep_id      INT UNSIGNED NOT NULL,
    until_sweep_id     INT UNSIGNED NULL,
    permissions        ENUM('private','public') NULL,
    size_bytes         BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_bucket_from (dh_bucket_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_bucket_id)   REFERENCES dh_buckets(dh_bucket_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
