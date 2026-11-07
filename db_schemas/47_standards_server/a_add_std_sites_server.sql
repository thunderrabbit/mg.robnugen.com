-- Which of a site's domains is the server the scanner asks what is deployed.
-- NULL means "the only one": a site whose linked domains all share one
-- checkout directory needs nothing named. A site with a test domain beside
-- production names production here (site.sh site-set --server=).

ALTER TABLE std_sites
    ADD COLUMN server_site_domain_id INT UNSIGNED NULL AFTER checkout_path,
    ADD CONSTRAINT fk_std_sites_server
        FOREIGN KEY (server_site_domain_id) REFERENCES dh_site_domains(dh_site_domain_id);
