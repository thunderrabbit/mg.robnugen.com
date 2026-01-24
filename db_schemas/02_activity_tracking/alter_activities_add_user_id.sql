-- Migration: Add user_id to activities table
-- Purpose: Enable users to create custom activities
-- NULL user_id = system activity, non-NULL = user-owned

-- Add user_id column (nullable for system activities)
ALTER TABLE activities
  ADD COLUMN user_id INT UNSIGNED NULL AFTER activity_id;

-- Add index for efficient querying by user
ALTER TABLE activities
  ADD KEY idx_user_activities (user_id, is_active);

-- Add foreign key constraint
ALTER TABLE activities
  ADD CONSTRAINT fk_activities_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE;
