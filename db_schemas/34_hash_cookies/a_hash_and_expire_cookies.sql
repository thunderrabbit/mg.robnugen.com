-- Remember-me cookies: store the SHA-256 of each token instead of the token,
-- and give every row a server-side expiry (mg #724).
--
-- Existing rows are hashed in place, so nobody is logged out: the browser
-- still holds the plaintext, and the new lookup hashes what it presents.
-- Each row expires 30 days after it was last used (or created, if never).
-- last_access is ON UPDATE CURRENT_TIMESTAMP, so the UPDATE sets it to
-- itself to keep the real last use. Rows already past expiry are deleted.

ALTER TABLE `cookies`
    MODIFY `cookie` CHAR(64) COLLATE utf8mb4_bin NOT NULL,
    ADD COLUMN `expires_at` DATETIME NULL AFTER `last_access`,
    ADD KEY `expires_at` (`expires_at`);

UPDATE `cookies`
SET `cookie` = SHA2(`cookie`, 256),
    `expires_at` = COALESCE(`last_access`, `created_at`) + INTERVAL 30 DAY,
    `last_access` = `last_access`;

DELETE FROM `cookies` WHERE `expires_at` <= NOW();

ALTER TABLE `cookies`
    MODIFY `expires_at` DATETIME NOT NULL;
