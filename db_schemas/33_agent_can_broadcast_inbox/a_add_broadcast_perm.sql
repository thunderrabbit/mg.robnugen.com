-- Add a permission bit gating inbox broadcast (recipient_aiu = NULL) sends.
-- Matches the existing pattern (can_read_inbox, can_ping_phone, etc.) on
-- agent_inbox_user. Deliberately no UPDATE here: every actor starts with
-- the bit off, so broadcast is disabled for everyone until it's granted
-- back to a specific agent via a direct UPDATE, same as can_ping_phone.

ALTER TABLE agent_inbox_user
    ADD COLUMN can_broadcast_inbox TINYINT(1) NOT NULL DEFAULT 0 AFTER can_ping_phone;
