-- Per-aiu password hash for the new /login/agent/ form (issue #122).
-- NULL = this aiu cannot log in via the web. Existing rows default to NULL.
-- VARCHAR(255) is the PHP manual's recommendation for password_hash() output;
-- Argon2id hashes run ~97 chars and PASSWORD_DEFAULT may grow over time.

ALTER TABLE agent_inbox_user
    ADD COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL AFTER actor_type;
