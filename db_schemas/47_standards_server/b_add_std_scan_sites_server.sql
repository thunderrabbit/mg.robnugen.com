-- What the site's server was running when the scanner asked. A site is
-- scanned only when its checkout is clean at server_commit, so these say what
-- the results describe. NULL when the server could not be asked. A server on a
-- detached HEAD has a commit and no branch.

ALTER TABLE std_scan_sites
    ADD COLUMN server_branch VARCHAR(100) NULL AFTER skipped_reason,
    ADD COLUMN server_commit CHAR(40) NULL AFTER server_branch;
