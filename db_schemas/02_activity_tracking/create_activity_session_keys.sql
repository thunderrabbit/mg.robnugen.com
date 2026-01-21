-- Create activity_session_keys table
-- Maps YouTube-style session keys to activity_kai records
-- Only used for Pro/admin users who need multiple concurrent timers

CREATE TABLE activity_session_keys (
  session_key VARCHAR(11) NOT NULL,           -- YouTube-style: "dQw4w9WgXcQ"
  ak_id BIGINT UNSIGNED NOT NULL UNIQUE,      -- FK to activity_kai (one-to-one)
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (session_key),
  UNIQUE KEY uk_ak_id (ak_id),

  CONSTRAINT fk_session_key_activity
    FOREIGN KEY (ak_id) REFERENCES activity_kai(ak_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- Index for looking up sessions by ak_id (reverse lookup)
-- Already covered by UNIQUE KEY uk_ak_id

-- Example data:
-- session_key  | ak_id | created_at_utc
-- -------------|-------|-------------------
-- dQw4w9WgXcQ  | 123   | 2026-01-22 00:15:00
-- xK7mP2nQ9vR  | 124   | 2026-01-22 00:20:00
-- aB3cD4eF5gH  | 125   | 2026-01-22 00:25:00
