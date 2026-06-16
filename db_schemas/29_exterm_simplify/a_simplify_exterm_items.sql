-- Simplify exterm_items into a plain on-the-go note (Exterminal v2 — phone notebook).
-- The original model carried kind/status/risk/approval semantics; that work now lives
-- in jikan issues. An ET note is just: project_id + author + title + body + timestamps.
-- Loose/greenfield ideas are filed into a real catch-all project (e.g. "Greenfield
-- Harebrain Ideas", project_id=27); promotion later re-files the note's project_id.
--
-- Drops the assignee FK + the two status-bearing indexes, then the now-unused columns,
-- and adds a recency index for "what was I thinking about?" listing across a project.

ALTER TABLE exterm_items
    DROP FOREIGN KEY fk_exterm_assignee;

ALTER TABLE exterm_items
    DROP INDEX idx_exterm_project_status,
    DROP INDEX idx_exterm_assignee_status;

ALTER TABLE exterm_items
    DROP COLUMN assignee_aiu,
    DROP COLUMN kind,
    DROP COLUMN status,
    DROP COLUMN risk,
    DROP COLUMN done_at_utc;

ALTER TABLE exterm_items
    ADD INDEX idx_exterm_project_updated (project_id, updated_at_utc);
