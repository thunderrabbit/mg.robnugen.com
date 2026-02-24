-- Audit log for credited API calls
-- One row per credit consumed (POST /sessions, GET /stats)
-- Free endpoint calls are not logged here (credits_remaining shows net balance)
-- endpoint is e.g. '/sessions', '/stats'
CREATE TABLE api_usage (
    usage_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    key_id     BIGINT UNSIGNED NOT NULL,
    endpoint   VARCHAR(128) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (usage_id),
    KEY idx_user_usage (user_id, created_at),
    KEY idx_key_usage  (key_id,  created_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id)    ON DELETE CASCADE,
    FOREIGN KEY (key_id)  REFERENCES api_keys(key_id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
