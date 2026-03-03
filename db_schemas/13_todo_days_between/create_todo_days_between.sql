ALTER TABLE todos
  ADD COLUMN do_every_n_days SMALLINT UNSIGNED NULL DEFAULT NULL
  COMMENT 'Interval-based recurrence: show todo N days after last completion'
  AFTER do_dates;
