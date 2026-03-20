-- Add show_after column to todos table
-- Separates "don't show before this date" from "due by this date"
-- due_date = deadline (should be done BY this date)
-- show_after = deferred visibility (don't show UNTIL this date)
-- NULL show_after = visible immediately (default, backwards compatible)
ALTER TABLE todos
    ADD COLUMN show_after DATE NULL DEFAULT NULL AFTER due_date;
