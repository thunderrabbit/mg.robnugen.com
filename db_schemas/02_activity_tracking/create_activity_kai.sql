-- Create activity_kai table for tracking activity sessions
CREATE TABLE activity_kai (
  ak_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  activity_id BIGINT UNSIGNED NOT NULL DEFAULT 1,  -- FK to activities table

  -- Timing
  start_local_dt DATETIME NOT NULL,
  intended_sec INT UNSIGNED NOT NULL,     -- What they set (e.g., 300 = 5 min)
  actual_sec INT UNSIGNED NULL,           -- How long they actually did it
  bonus_sec INT UNSIGNED NOT NULL DEFAULT 0,  -- Extra time beyond countdown

  -- Timezone context
  timezone_id SMALLINT UNSIGNED NOT NULL,  -- FK to timezones table

  -- Metadata
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (ak_id),
  KEY idx_user_start (user_id, start_local_dt),
  KEY idx_user_activity (user_id, activity_id, start_local_dt),
  KEY idx_activity (activity_id),
  KEY idx_timezone (timezone_id),

  CONSTRAINT fk_activity_kai_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_activity_kai_activity
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_activity_kai_timezone
    FOREIGN KEY (timezone_id) REFERENCES timezones(timezone_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;
