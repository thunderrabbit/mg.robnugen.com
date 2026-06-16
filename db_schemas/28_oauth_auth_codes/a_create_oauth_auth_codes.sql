-- Single-use authorization codes issued by /authorize during the OAuth
-- Authorization Code + PKCE flow (RFC 6749 §4.1 + RFC 7636).
-- Codes expire in 10 minutes; used_at_utc is stamped on exchange.
CREATE TABLE oauth_auth_codes (
    code_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aiu_id          INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    code_hash       CHAR(64) NOT NULL,
    code_challenge  VARCHAR(128) NOT NULL,
    redirect_uri    VARCHAR(500) NOT NULL,
    state           VARCHAR(256) NOT NULL,
    expires_at_utc  DATETIME NOT NULL,
    used_at_utc     DATETIME NULL,
    PRIMARY KEY (code_id),
    UNIQUE KEY uidx_oauth_code_hash (code_hash),
    INDEX idx_oauth_code_exp (expires_at_utc),
    CONSTRAINT fk_oauth_code_aiu FOREIGN KEY (aiu_id) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
