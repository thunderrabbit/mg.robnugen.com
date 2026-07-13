-- Remembers a prior "Authorize" approval for a given (user, client_id,
-- redirect_uri) so /authorize can skip the manual approval form on
-- reconnect. Matching is exact on both client_id and redirect_uri so a
-- third party can't ride an existing consent by reusing a known client_id
-- with a different callback target.
CREATE TABLE oauth_client_consents (
    consent_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    aiu_id          INT UNSIGNED NOT NULL,
    client_id       VARCHAR(255) NOT NULL,
    redirect_uri    VARCHAR(512) NOT NULL,
    created_at_utc  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (consent_id),
    UNIQUE KEY uidx_consent_user_client_redirect (user_id, client_id, redirect_uri),
    CONSTRAINT fk_consent_aiu FOREIGN KEY (aiu_id) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
