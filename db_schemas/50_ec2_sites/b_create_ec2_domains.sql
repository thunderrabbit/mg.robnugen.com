-- A hostname an EC2 server serves, and the checkout directory behind it, as
-- an absolute path on that server. Entered by hand (site.sh ec2-domain-add);
-- the sweep probes only what is entered here and discovers nothing.

CREATE TABLE ec2_domains (
    ec2_domain_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ec2_server_id INT UNSIGNED  NOT NULL,
    domain        VARCHAR(255)  NOT NULL,
    directory     VARCHAR(255)  NOT NULL,
    UNIQUE KEY uq_ec2_domain (domain),
    FOREIGN KEY (ec2_server_id) REFERENCES ec2_servers(ec2_server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
