CREATE TABLE IF NOT EXISTS dm_user_config (
    user_id INT UNSIGNED PRIMARY KEY,
    passphrase_verify BLOB NOT NULL COMMENT 'encrypted known string for passphrase verification',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
