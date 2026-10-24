-- Add a permission bit gating repository create/update/deactivate for
-- agents. Matches the existing pattern (can_ping_phone, can_broadcast_inbox)
-- on agent_inbox_user. A human key on an admin account needs no bit; every
-- other actor starts with it off and is granted it by a direct UPDATE.
-- Boss Claude (aiu 8) is the first agent granted it.

ALTER TABLE agent_inbox_user
    ADD COLUMN can_write_repos TINYINT(1) NOT NULL DEFAULT 0 AFTER can_broadcast_inbox;

UPDATE agent_inbox_user SET can_write_repos = 1 WHERE aiu_id = 8;
