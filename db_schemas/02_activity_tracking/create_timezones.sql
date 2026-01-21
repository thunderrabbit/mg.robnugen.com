-- Create timezones lookup table
CREATE TABLE timezones (
  timezone_id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iana_name VARCHAR(64) NOT NULL UNIQUE,
  display_name VARCHAR(128) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,

  PRIMARY KEY (timezone_id),
  UNIQUE KEY uq_iana_name (iana_name)
) ENGINE=InnoDB;

-- Common timezones (covering all major cities and regions)
INSERT INTO timezones (iana_name, display_name) VALUES
  -- Asia/Pacific
  ('Asia/Tokyo', 'Japan Standard Time'),
  ('Asia/Seoul', 'Korea Standard Time'),
  ('Asia/Shanghai', 'China Standard Time'),
  ('Asia/Hong_Kong', 'Hong Kong Time'),
  ('Asia/Taipei', 'Taipei Time'),
  ('Asia/Singapore', 'Singapore Time'),
  ('Asia/Bangkok', 'Indochina Time'),
  ('Asia/Jakarta', 'Western Indonesia Time'),
  ('Asia/Manila', 'Philippine Time'),
  ('Asia/Kuala_Lumpur', 'Malaysia Time'),
  ('Asia/Ho_Chi_Minh', 'Indochina Time'),
  ('Asia/Kolkata', 'India Standard Time'),
  ('Asia/Dhaka', 'Bangladesh Time'),
  ('Asia/Karachi', 'Pakistan Standard Time'),
  ('Asia/Dubai', 'Gulf Standard Time'),
  ('Asia/Riyadh', 'Arabia Standard Time'),
  ('Asia/Tehran', 'Iran Standard Time'),
  ('Asia/Jerusalem', 'Israel Standard Time'),
  ('Asia/Istanbul', 'Turkey Time'),

  -- Australia/New Zealand
  ('Australia/Sydney', 'Australian Eastern Time'),
  ('Australia/Melbourne', 'Australian Eastern Time'),
  ('Australia/Brisbane', 'Australian Eastern Time'),
  ('Australia/Adelaide', 'Australian Central Time'),
  ('Australia/Perth', 'Australian Western Time'),
  ('Australia/Darwin', 'Australian Central Time'),
  ('Pacific/Auckland', 'New Zealand Standard Time'),

  -- Americas - North America
  ('America/New_York', 'Eastern Time'),
  ('America/Chicago', 'Central Time'),
  ('America/Denver', 'Mountain Time'),
  ('America/Phoenix', 'Mountain Standard Time'),
  ('America/Los_Angeles', 'Pacific Time'),
  ('America/Anchorage', 'Alaska Time'),
  ('Pacific/Honolulu', 'Hawaii-Aleutian Time'),
  ('America/Toronto', 'Eastern Time'),
  ('America/Vancouver', 'Pacific Time'),
  ('America/Edmonton', 'Mountain Time'),
  ('America/Winnipeg', 'Central Time'),
  ('America/Halifax', 'Atlantic Time'),
  ('America/Mexico_City', 'Central Time'),
  ('America/Monterrey', 'Central Time'),
  ('America/Tijuana', 'Pacific Time'),

  -- Americas - South America
  ('America/Sao_Paulo', 'Brasilia Time'),
  ('America/Buenos_Aires', 'Argentina Time'),
  ('America/Santiago', 'Chile Time'),
  ('America/Lima', 'Peru Time'),
  ('America/Bogota', 'Colombia Time'),
  ('America/Caracas', 'Venezuela Time'),

  -- Europe
  ('Europe/London', 'Greenwich Mean Time'),
  ('Europe/Dublin', 'Greenwich Mean Time'),
  ('Europe/Paris', 'Central European Time'),
  ('Europe/Berlin', 'Central European Time'),
  ('Europe/Rome', 'Central European Time'),
  ('Europe/Madrid', 'Central European Time'),
  ('Europe/Amsterdam', 'Central European Time'),
  ('Europe/Brussels', 'Central European Time'),
  ('Europe/Vienna', 'Central European Time'),
  ('Europe/Zurich', 'Central European Time'),
  ('Europe/Stockholm', 'Central European Time'),
  ('Europe/Copenhagen', 'Central European Time'),
  ('Europe/Oslo', 'Central European Time'),
  ('Europe/Helsinki', 'Eastern European Time'),
  ('Europe/Athens', 'Eastern European Time'),
  ('Europe/Warsaw', 'Central European Time'),
  ('Europe/Prague', 'Central European Time'),
  ('Europe/Budapest', 'Central European Time'),
  ('Europe/Bucharest', 'Eastern European Time'),
  ('Europe/Moscow', 'Moscow Standard Time'),
  ('Europe/Kiev', 'Eastern European Time'),
  ('Europe/Lisbon', 'Western European Time'),

  -- Africa
  ('Africa/Cairo', 'Eastern European Time'),
  ('Africa/Johannesburg', 'South Africa Standard Time'),
  ('Africa/Lagos', 'West Africa Time'),
  ('Africa/Nairobi', 'East Africa Time'),
  ('Africa/Casablanca', 'Western European Time'),
  ('Africa/Algiers', 'Central European Time'),
  ('Africa/Tunis', 'Central European Time'),

  -- Atlantic
  ('Atlantic/Reykjavik', 'Greenwich Mean Time'),
  ('Atlantic/Azores', 'Azores Time'),
  ('Atlantic/Cape_Verde', 'Cape Verde Time'),

  -- Indian Ocean
  ('Indian/Mauritius', 'Mauritius Time'),
  ('Indian/Maldives', 'Maldives Time'),

  -- Pacific Islands
  ('Pacific/Fiji', 'Fiji Time'),
  ('Pacific/Guam', 'Chamorro Standard Time'),
  ('Pacific/Tahiti', 'Tahiti Time'),

  -- UTC
  ('UTC', 'Coordinated Universal Time');

