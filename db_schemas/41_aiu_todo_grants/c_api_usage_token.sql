-- Bearer-token callers (limited actors) have no api_keys row, so usage is
-- logged against the mcp_tokens row instead. Exactly one of key_id/token_id is set.
ALTER TABLE api_usage
    MODIFY key_id BIGINT UNSIGNED NULL,
    ADD COLUMN token_id BIGINT UNSIGNED NULL AFTER key_id,
    ADD KEY idx_token_usage (token_id, created_at),
    ADD CONSTRAINT fk_usage_token FOREIGN KEY (token_id) REFERENCES mcp_tokens(token_id) ON DELETE SET NULL;
