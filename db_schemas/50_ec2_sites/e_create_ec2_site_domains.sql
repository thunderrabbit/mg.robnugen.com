-- The link from a site to an EC2 hostname, beside dh_site_domains for
-- DreamHost. A site's links are all of one kind (Inventory\Standards\
-- LinkKindRule). path_prefix means what it means in dh_site_domains: the
-- path inside the hostname for a site that is only part of one.

CREATE TABLE ec2_site_domains (
    ec2_site_domain_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_site_id        INT UNSIGNED NOT NULL,
    ec2_domain_id      INT UNSIGNED NOT NULL,
    path_prefix        VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_ec2_domain_path (ec2_domain_id, path_prefix),
    FOREIGN KEY (std_site_id)   REFERENCES std_sites(std_site_id),
    FOREIGN KEY (ec2_domain_id) REFERENCES ec2_domains(ec2_domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
