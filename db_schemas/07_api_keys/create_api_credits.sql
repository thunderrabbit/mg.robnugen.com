-- Credit balance for metered API usage
-- One row per user; balance is topped up by Stripe webhook or seeded at registration
-- Writes cost 1 credit (POST /sessions, GET /stats); reads are free
CREATE TABLE api_credits (
    credit_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    credits_remaining INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (credit_id),
    UNIQUE KEY uniq_user_credits (user_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
