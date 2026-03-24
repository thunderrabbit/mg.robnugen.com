-- Add sender_timezone and rename timestamp columns to _utc
-- Prereq: Base.php already changed to SET time_zone = '+00:00'
-- Prereq: All existing timestamps already backfilled to UTC

ALTER TABLE agent_inbox
    ADD COLUMN sender_timezone VARCHAR(64) NULL AFTER priority;

ALTER TABLE agent_inbox
    CHANGE COLUMN created_at created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CHANGE COLUMN updated_at updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);
