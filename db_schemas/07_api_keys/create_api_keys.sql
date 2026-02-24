-- API keys for external agent authentication
-- Each user can have multiple keys (e.g. one per agent/tool)
-- Keys are CHAR(64): 'sk_' prefix + 61 random chars from Utilities::randomString(61)
CREATE TABLE api_keys (
    key_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    api_key     CHAR(64) NOT NULL,
    label       VARCHAR(255) NULL,                          -- human-readable name, e.g. "my sleep agent"
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used   DATETIME NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,             -- soft delete / revoke

    PRIMARY KEY (key_id),
    UNIQUE KEY uniq_api_key (api_key),
    KEY idx_user_keys (user_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
