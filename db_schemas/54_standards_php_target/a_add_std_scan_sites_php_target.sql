-- The PHP a site is moving to, as standards-mcp's repos.yaml gave it
-- (`php_target`) when the site was scanned. A step whose param is `target`
-- is measured against it, so a site heading for 8.3 is not asked about 8.5.
-- It is a scan-time fact, like server_commit: repos.yaml on Lemur 13 is the
-- one place it is set, and mg keeps what the latest scan saw. NULL when the
-- registry gives the repo no target, or for scans before this column.

ALTER TABLE std_scan_sites
    ADD COLUMN php_target VARCHAR(8) NULL AFTER server_commit;
