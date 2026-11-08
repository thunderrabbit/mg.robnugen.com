-- Which EC2 link is a site's server and which its test hostname, with the
-- meaning of server_site_domain_id and test_site_domain_id (migrations 47 and
-- 48) for a site whose links are EC2 ones. A site uses one pair or the other,
-- never both; set with site.sh site-set --server= / --test= as before.

ALTER TABLE std_sites
    ADD COLUMN server_ec2_site_domain_id INT UNSIGNED NULL AFTER upgrade_branch,
    ADD COLUMN test_ec2_site_domain_id   INT UNSIGNED NULL AFTER server_ec2_site_domain_id,
    ADD CONSTRAINT fk_std_sites_server_ec2
        FOREIGN KEY (server_ec2_site_domain_id) REFERENCES ec2_site_domains(ec2_site_domain_id),
    ADD CONSTRAINT fk_std_sites_test_ec2
        FOREIGN KEY (test_ec2_site_domain_id) REFERENCES ec2_site_domains(ec2_site_domain_id);
