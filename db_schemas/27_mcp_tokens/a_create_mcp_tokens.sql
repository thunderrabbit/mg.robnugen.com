-- Short-lived access tokens issued by the MCP OAuth token endpoint (mcp/token.php).
-- Clients exchange an API key (client_secret) for a token via Client Credentials grant.
-- The raw token is returned once; only the SHA-256 hash is stored here.
-- Expired rows are pruned probabilistically on each token request.
CREATE TABLE mcp_tokens (
    token_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aiu_id          INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    expires_at_utc  DATETIME NOT NULL,
    created_at_utc  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (token_id),
    UNIQUE KEY uidx_mcp_token_hash (token_hash),
    INDEX idx_mcp_token_aiu_exp (aiu_id, expires_at_utc),
    CONSTRAINT fk_mcp_token_aiu FOREIGN KEY (aiu_id) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
