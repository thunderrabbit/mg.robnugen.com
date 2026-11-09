-- A DreamHost sweep per day was the rule: the directory was named by the
-- date, and the importer refused a second sweep the same day. A domain
-- created after the daily sweep then had to wait until tomorrow before it
-- could be linked to its site. Sweeps are now named by date and minute and
-- ordered by swept_at_utc, so two sweeps a day may coexist; swept_on stays
-- as the day each belongs to.
ALTER TABLE dh_sweeps
    DROP KEY uq_account_swept_on,
    ADD UNIQUE KEY uq_account_swept_at (dh_account_id, swept_at_utc);
