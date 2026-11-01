-- A name the user gives a signed-in browser on /profile/devices/, such as
-- "Firefox on Lemur 13" or "Ma's Laptop in Riverdell", shown in place of
-- the label worked out from user_agent. NULL means no name was given, and the
-- page falls back to that label. Auth\DeviceName caps the length at 60.
-- Keep semicolons out of these comments. executeMultipleSQL() splits on them.
ALTER TABLE `cookies`
  ADD COLUMN `device_name` VARCHAR(60) DEFAULT NULL AFTER `user_agent`;
