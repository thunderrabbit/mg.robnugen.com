-- The branch a site is preparing to replace what its server runs: a long
-- upgrade (jQuery, a PHP major) that lives on its own line for months while
-- production takes hotfixes. NULL for a site whose checkout follows its server.
-- Set by hand with site.sh site-set --upgrade=; clear it with --upgrade= when
-- the line is promoted. The scanner checks such a site at that branch's tip,
-- and the page says so beside what the server runs.

ALTER TABLE std_sites
    ADD COLUMN upgrade_branch VARCHAR(100) NULL AFTER test_site_domain_id;
