-- Add coarse permission bits for the project-management subsystem.
-- Matches the existing pattern (can_read_inbox, can_read_todos, etc.) on agent_inbox_user.
-- API handlers gate on these at the top of each file; fine-grained access within the
-- subsystem continues to flow through project_members.

ALTER TABLE agent_inbox_user
    ADD COLUMN can_read_project  TINYINT(1) NOT NULL DEFAULT 0 AFTER can_write_emotions,
    ADD COLUMN can_write_project TINYINT(1) NOT NULL DEFAULT 0 AFTER can_read_project;

-- Flip the bits on for:
--   aiu 1  (gambare / Rob's human actor)
--   aiu 35 (mgClaude — mg.robnugen.com codebase manager)
--   aiu 11 (Dr_Hilbert_Space_mgTester — MG_TEST_AIU_FULL; keeps existing API tests green)
UPDATE agent_inbox_user
SET can_read_project = 1, can_write_project = 1
WHERE aiu_id IN (1, 35, 11);
