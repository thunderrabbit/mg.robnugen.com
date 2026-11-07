-- The branch a site deployed was kept by hand and went stale on every deploy
-- of a different branch. The scanner now asks the site's server which commit
-- it runs and std_scan_sites keeps what it said, so the column has no reader.

ALTER TABLE std_sites DROP COLUMN deployed_branch;
