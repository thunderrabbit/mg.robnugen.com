-- Adds refresh-token support to mcp_tokens so the authorization_code grant
-- (used by the interactive Claude connector) can silently renew access
-- tokens instead of forcing the user back through /authorize every 24h.
-- NULL for tokens issued via client_credentials, which don't need refresh.
ALTER TABLE mcp_tokens
    ADD COLUMN refresh_token_hash     CHAR(64)  NULL AFTER token_hash,
    ADD COLUMN refresh_expires_at_utc DATETIME  NULL AFTER expires_at_utc,
    ADD UNIQUE KEY uidx_mcp_refresh_token_hash (refresh_token_hash);
