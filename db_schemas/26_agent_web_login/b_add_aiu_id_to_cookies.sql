-- Track which aiu owns a cookie, so the web layer can scope a session to an
-- agent identity instead of treating every cookie as a human (issue #122).
-- NULL = human session (existing behavior); non-null = agent session, which
-- short-circuits isAdmin()/isPaid() to false.

ALTER TABLE cookies
    ADD COLUMN aiu_id INT UNSIGNED NULL DEFAULT NULL AFTER user_id,
    ADD INDEX idx_cookies_aiu_id (aiu_id);
