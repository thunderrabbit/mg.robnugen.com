-- Add show_date column to agent_inbox table
-- Controls when an inbox message becomes visible in the default list.
-- NULL means visible immediately (same as created_at behavior).
-- Set to a future datetime to defer visibility (e.g. back-burner items).
ALTER TABLE agent_inbox
    ADD COLUMN show_date DATETIME NULL DEFAULT NULL AFTER priority;
