-- Migration: Add type ENUM column to activities table
-- Purpose: Replace is_pro boolean with more expressive type system
-- Types:
--   FREE    = Available to everyone (anonymous + logged-in)
--   PUBLIC  = Available to logged-in users (system activities)
--   PRIVATE = User-created activities (only visible to owner)

-- Step 1: Add the type column with default PRIVATE (for future user-created activities)
ALTER TABLE activities
  ADD COLUMN type ENUM('FREE', 'PUBLIC', 'PRIVATE') NOT NULL DEFAULT 'PRIVATE'
  AFTER is_pro;

-- Step 2: Populate type based on existing data
-- FREE: is_pro = 0 (Meditation)
UPDATE activities SET type = 'FREE' WHERE is_pro = 0;

-- PUBLIC: is_pro = 1 AND user_id IS NULL (system pro activities)
UPDATE activities SET type = 'PUBLIC' WHERE is_pro = 1 AND user_id IS NULL;

-- PRIVATE: user_id IS NOT NULL (user-created, none exist yet but future-proof)
UPDATE activities SET type = 'PRIVATE' WHERE user_id IS NOT NULL;

-- Step 3: Add index for efficient type-based queries
ALTER TABLE activities
  ADD KEY idx_activities_type (type, is_active);
