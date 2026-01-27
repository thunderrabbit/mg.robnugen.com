-- Main table for defining tasks, habits, and todos
CREATE TABLE todos (
    todo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    -- Functional Configuration
    is_timer TINYINT(1) NOT NULL DEFAULT 0,         -- Requires a timer?
    is_counter TINYINT(1) NOT NULL DEFAULT 0,       -- Requires counting? (target_count > 1)

    -- Integration with Activity System
    activity_id BIGINT UNSIGNED NULL,               -- If set, this todo is completed by doing this activity
                                                    -- FK to activities(activity_id)

    -- Goals
    target_count INT UNSIGNED NULL DEFAULT 1,       -- Default 1 (checkbox). >1 for "8 glasses"
    target_duration_seconds INT UNSIGNED NULL,      -- If is_timer=1, this is the goal per session (e.g. 600)

    -- Scheduling / Recurrence
    -- For "Weekly Tasks" (e.g. Mon, Wed, Fri) use do_days
    do_days SET('Sun','Mon','Tue','Wed','Thu','Fri','Sat') NULL,

    -- For "Monthly Tasks" (e.g. 5th and 25th) use do_dates
    do_dates SET('1','2','3','4','5','6','7','8','9','10',
                 '11','12','13','14','15','16','17','18','19','20',
                 '21','22','23','24','25','26','27','28','29','30','31') NULL,

    -- Target time of day (NULL for flexible/multi-frequency items)
    do_time TIME NULL,                              -- e.g. '08:00:00' for 8am

    -- For One-time items (no recurrence set)
    due_date DATETIME NULL,                         -- Single due date

    -- Status
    is_active TINYINT(1) NOT NULL DEFAULT 1,        -- Soft delete / archive

    -- Timestamps
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    PRIMARY KEY (todo_id),
    KEY idx_user_todos (user_id, is_active),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample todos for user_id=1 (based on "A Day in the Life" user story)
-- activity_id references: 1=Meditation, 2=Sleeping, 3=Networking, 4=Work, 5=Physical activity
INSERT INTO todos (user_id, title, is_timer, is_counter, activity_id, target_count, target_duration_seconds, do_days, do_time) VALUES
  -- Simple daily habits (checkbox style)
  (1, 'Wake Up', 0, 0, NULL, 1, NULL, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),
  (1, 'Brush Teeth', 0, 0, NULL, 1, NULL, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),
  (1, 'Take Supplements', 0, 0, NULL, 1, NULL, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),
  (1, 'Shower', 0, 0, NULL, 1, NULL, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),

  -- Counter-based habit (multiple times per day, so do_time=NULL)
  (1, 'Drink Water', 0, 1, NULL, 8, NULL, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),

  -- Timed habit on specific weekdays at 8am (30 min = 1800 sec)
  (1, 'Block Therapy', 1, 0, NULL, 1, 1800, 'Mon,Tue,Wed,Thu', '08:00:00'),

  -- Timed + counted habits linked to activities (do_time=NULL for flexible/multi-frequency)
  (1, 'Meditate', 1, 0, 1, 2, 600, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),  -- 2x daily, 10 min each
  (1, 'Work', 1, 0, 4, 1, NULL, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),      -- activity_id=4, no duration goal
  (1, 'Enjoy Sunshine', 1, 0, NULL, 1, NULL, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),  -- No activity link yet
  (1, 'Networking', 1, 0, 3, 1, NULL, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL),  -- activity_id=3
  (1, 'Sleep', 1, 0, 2, 1, 25200, 'Sun,Mon,Tue,Wed,Thu,Fri,Sat', NULL)       -- 7h goal, activity_id=2
;
