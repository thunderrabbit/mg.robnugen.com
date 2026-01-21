-- Create activity_kai table for tracking activity sessions
CREATE TABLE activity_kai (
  activity_kai_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  activity_id BIGINT UNSIGNED NOT NULL DEFAULT 1,  -- FK to activities table

  -- Timing
  start_local_dt DATETIME(6) NOT NULL,
  intended_duration_sec INT UNSIGNED NOT NULL,     -- What they set (e.g., 300 = 5 min)
  actual_duration_sec INT UNSIGNED NULL,           -- How long they actually did it
  bonus_duration_sec INT UNSIGNED NOT NULL DEFAULT 0,  -- Extra time beyond countdown

  -- Timezone context
  start_tz VARCHAR(64) NOT NULL,
  start_utc_offset_min SMALLINT NOT NULL,

  -- Metadata
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (activity_kai_id),
  KEY idx_user_start (user_id, start_local_dt),
  KEY idx_user_activity (user_id, activity_id, start_local_dt),
  KEY idx_activity (activity_id),

  CONSTRAINT fk_activity_kai_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_activity_kai_activity
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;
