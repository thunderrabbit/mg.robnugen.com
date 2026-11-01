-- The timestamps PHP wrote to cookies (last_access, expires_at) were
-- formatted in America/Los_Angeles but read by a UTC session, so every row
-- sits seven or eight hours behind created_at, which is MySQL's own clock
-- and was always right. Database\CookieRepository writes UTC from 0.56.0.
-- This moves the rows it did not write.
--
-- A row from the old code expires hours short of its 30-day lifetime after
-- created_at. A row from the new code expires exactly a lifetime after, so
-- the WHERE leaves it alone and running this twice changes nothing.
-- CONVERT_TZ uses the server's zone tables, which this host has.
-- last_access is ON UPDATE CURRENT_TIMESTAMP, so it is set in the same
-- statement or MySQL would stamp it with now.
-- Keep semicolons out of these comments. executeMultipleSQL() splits on them.

UPDATE `cookies`
SET `last_access` = CONVERT_TZ(`last_access`, 'America/Los_Angeles', '+00:00'),
    `expires_at` = CONVERT_TZ(`expires_at`, 'America/Los_Angeles', '+00:00')
WHERE `expires_at` < `created_at` + INTERVAL 30 DAY - INTERVAL 1 HOUR;
