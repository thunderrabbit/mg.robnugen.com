-- Create timezones lookup table
CREATE TABLE timezones (
  timezone_id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iana_name VARCHAR(64) NOT NULL UNIQUE,
  display_name VARCHAR(128) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,

  PRIMARY KEY (timezone_id),
  UNIQUE KEY uq_iana_name (iana_name)
) ENGINE=InnoDB;

-- Common timezones (add more as needed)
INSERT INTO timezones (iana_name, display_name) VALUES
  ('Asia/Tokyo', 'Japan Standard Time'),
  ('Australia/Adelaide', 'Australian Central Time'),
  ('Australia/Perth', 'Australian Western Time'),
  ('Australia/Sydney', 'Australian Eastern Time'),
  ('Asia/Bangkok', 'Indochina Time'),
  ('America/Los_Angeles', 'Pacific Time'),
  ('America/New_York', 'Eastern Time'),
  ('America/Chicago', 'Central Time'),
  ('America/Denver', 'Mountain Time'),
  ('Europe/London', 'Greenwich Mean Time'),
  ('Europe/Paris', 'Central European Time'),
  ('UTC', 'Coordinated Universal Time');

