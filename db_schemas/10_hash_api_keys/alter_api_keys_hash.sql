-- Hash API keys: store SHA-256 hashes instead of plaintext keys.
-- All existing plaintext keys are invalidated and removed.
-- After applying this migration, users must generate new API keys.

-- Remove all existing plaintext keys (they are no longer valid).
-- DELETE (not TRUNCATE) so the ON DELETE CASCADE on api_usage fires,
-- cleaning up dependent usage rows automatically.
DELETE FROM api_keys;

-- Rename column and update index to reflect hash storage
ALTER TABLE api_keys
    CHANGE COLUMN api_key api_key_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex hash of the raw key',
    DROP INDEX uniq_api_key,
    ADD UNIQUE KEY uniq_api_key_hash (api_key_hash);
