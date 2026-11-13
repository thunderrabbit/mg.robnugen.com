-- The commit a site's test domain ran when the site was scanned, as the
-- scanner was told over ssh. Set only when the scanner asked: the checkout
-- was not at the server's commit and the site has no upgrade branch. A scan
-- whose commit_sha equals it read what the test domain runs. NULL otherwise,
-- and for scans before this column.
-- Apply before the next scan: the importer writes this column.

ALTER TABLE std_scan_sites
    ADD COLUMN test_commit CHAR(40) NULL AFTER server_commit;
