-- Create activities lookup table
CREATE TABLE activities (
  activity_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_name VARCHAR(64) NOT NULL,
  description TEXT NULL,
  is_pro TINYINT(1) NOT NULL DEFAULT 0,  -- 0 = free, 1 = Pro only
  is_active TINYINT(1) NOT NULL DEFAULT 1,  -- Can disable activities
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (activity_id)
) ENGINE=InnoDB;

-- Initial activity types
INSERT INTO activities (activity_id, activity_name, description, is_pro) VALUES
  (1, 'Meditation', 'Meditation practice', 0),
  (2, 'Sleeping', 'Sleep tracking', 1),
  (3, 'Networking', 'Post, Host, Invite, Write', 1),
  (4, 'Work', 'Professional work', 1),
  (5, 'Physical activity', 'Exercise', 1),
  (6, 'Hard mode', 'Nofap', 1),
  (7, 'Creativity', 'Creative practice', 1),
  (8, 'Minecraft', 'Minecraft', 1)
;
