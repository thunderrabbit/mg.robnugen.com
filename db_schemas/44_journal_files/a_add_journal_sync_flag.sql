-- Mark the one actor that syncs journal files from Lemur 13.
-- 1 = the actor may reach /journal-sync/* and nothing else in /api/v1.
-- 0 = every other actor, which may never reach /journal-sync/*.
-- See docs/superpowers/specs/2026-11-05-journal-editing-design.md.

ALTER TABLE agent_inbox_user
    ADD COLUMN journal_sync TINYINT(1) NOT NULL DEFAULT 0 AFTER todos_limited;
