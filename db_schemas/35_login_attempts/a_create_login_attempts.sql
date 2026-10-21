-- Failed password logins, for Auth\LoginThrottle (mg #726).
-- One row per FAILED password login. Successes delete the username's rows.
-- Lower-cased username as typed, not a user_id: guesses at names that do not
-- exist must count too, or the throttle itself reveals which names exist.
-- LoginThrottle treats a missing table as no throttle, so login keeps working
-- until this is applied.

CREATE TABLE `login_attempts` (
  `login_attempt_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(255) NOT NULL,
  `ip_address` VARBINARY(16) DEFAULT NULL,
  `attempted_at` DATETIME NOT NULL,
  KEY `username_attempted_at` (`username`, `attempted_at`),
  KEY `ip_attempted_at` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
