-- Add a priority field to issues, mirroring agent_inbox.priority's ENUM shape.
-- Positioned as the second column (right after the PRIMARY KEY) so priority is
-- visually prominent in table inspections.

ALTER TABLE issues
    ADD COLUMN priority ENUM('low', 'normal', 'high') NOT NULL DEFAULT 'normal' AFTER issue_id;

-- Backfill: set the high-priority bug we just opened (#16) to priority='high'
-- so the priority value is used from day one and the "HIGH PRIORITY" prefix in
-- the title can be dropped in a later UI slice.
UPDATE issues SET priority = 'high'
WHERE issue_id = 16;
