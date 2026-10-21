-- Stripe events already handled, so a redelivered event is applied once
-- (mg #742). Stripe delivers each event at least once and retries after any
-- non-2xx reply, so without this a retry could add a plan's credits twice.
-- The webhook inserts the event id in the same transaction as its effects.

CREATE TABLE `stripe_events` (
  `stripe_event_id` VARCHAR(255) NOT NULL PRIMARY KEY,
  `event_type` VARCHAR(100) NOT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
