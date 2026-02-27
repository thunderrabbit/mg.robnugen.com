CREATE TABLE my_ids_for_my_users_state (
    mifmus_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key_id  BIGINT UNSIGNED NOT NULL,
    my_id       BIGINT UNSIGNED NOT NULL   COMMENT 'agent private numeric handle (random)',
    state       TEXT NOT NULL              COMMENT 'XSalsa20-Poly1305 secretbox encrypted label',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (mifmus_id),
    UNIQUE KEY uniq_key_myid (api_key_id, my_id),
    KEY idx_api_key_id (api_key_id),
    FOREIGN KEY (api_key_id) REFERENCES api_keys(key_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE interaction_sessions (
    session_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key_id      BIGINT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    start_time      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (session_id),
    KEY idx_key_recent (api_key_id, last_event_time),
    FOREIGN KEY (api_key_id) REFERENCES api_keys(key_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE interaction_events (
    event_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id        BIGINT UNSIGNED NOT NULL,
    api_key_id        BIGINT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    mifmus_id         BIGINT UNSIGNED NULL          COMMENT 'NULL if event has no state tag',
    event_timestamp   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_type        ENUM('agent_action','user_input','user_reaction') NOT NULL,
    sequence_num      INT UNSIGNED NOT NULL         COMMENT 'within session assigned by PHP',
    encrypted_content TEXT NOT NULL                COMMENT 'XSalsa20-Poly1305 secretbox encrypted',

    PRIMARY KEY (event_id),
    UNIQUE KEY uniq_session_seq (session_id, sequence_num),
    KEY idx_mifmus (mifmus_id),
    KEY idx_user_time (user_id, event_timestamp),
    KEY idx_session_events (session_id, sequence_num),
    FOREIGN KEY (session_id) REFERENCES interaction_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (api_key_id) REFERENCES api_keys(key_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (mifmus_id) REFERENCES my_ids_for_my_users_state(mifmus_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
