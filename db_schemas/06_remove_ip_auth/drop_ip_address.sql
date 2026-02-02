-- Remove IP address column since user agent MD5 validation is sufficient
-- This allows users to switch networks without re-authentication

ALTER TABLE `cookies`
DROP COLUMN `ip_address`;
