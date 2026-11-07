-- Which of a site's domains is its test domain. NULL means the site has none,
-- and the steps that need one do not apply to it. Set by hand with
-- site.sh site-set --test=; never the same link as server_site_domain_id.

ALTER TABLE std_sites
    ADD COLUMN test_site_domain_id INT UNSIGNED NULL AFTER server_site_domain_id,
    ADD CONSTRAINT fk_std_sites_test
        FOREIGN KEY (test_site_domain_id) REFERENCES dh_site_domains(dh_site_domain_id);
