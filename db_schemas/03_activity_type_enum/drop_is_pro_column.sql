-- Migration: Drop deprecated is_pro column from activities table
-- IMPORTANT: Only run this AFTER all code has been updated to use the 'type' column
-- Run alter_activities_add_type.sql first, update code, then run this

-- Drop the deprecated is_pro column
ALTER TABLE activities
  DROP COLUMN is_pro;
