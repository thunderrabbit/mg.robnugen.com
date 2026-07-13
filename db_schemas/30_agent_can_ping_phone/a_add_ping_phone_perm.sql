-- Add a coarse permission bit letting an agent ping Rob's phone (Telegram).
-- Matches the existing pattern (can_read_inbox, can_write_project, etc.) on
-- agent_inbox_user. The /notify/send API handler gates on this bit; the token
-- and chat_id live server-side in Config, so the capability is brokered, not
-- handed to the agent.

ALTER TABLE agent_inbox_user
    ADD COLUMN can_ping_phone TINYINT(1) NOT NULL DEFAULT 0 AFTER can_write_project;

-- Flip the bit on for the interactive Claude front-ends Rob wants reachable to
-- start (confirmed via Telegram). Deliberately NOT the auto-testers/pollers.
--   8  Boss Claude
--   26 abbClaude
--   32 abfClaude
--   33 eliClaude
--   35 mgClaude
UPDATE agent_inbox_user
SET can_ping_phone = 1
WHERE aiu_id IN (8, 26, 32, 33, 35);
