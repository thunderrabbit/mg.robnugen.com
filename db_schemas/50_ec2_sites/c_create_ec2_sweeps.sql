-- One visit of tools/ec2-sweep to one server, written by
-- tools/import_ec2_sweep.php. web_server and cli_php are the server-level
-- facts the script reports: the Server header of the first probe answer, and
-- `php -v`, kept for the page and for contrast with the web PHP, which is per
-- hostname and lives in ec2_domain_states.

CREATE TABLE ec2_sweeps (
    ec2_sweep_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ec2_server_id INT UNSIGNED NOT NULL,
    swept_at_utc  DATETIME     NOT NULL,
    web_server    VARCHAR(100) NULL,
    cli_php       VARCHAR(16)  NULL,
    FOREIGN KEY (ec2_server_id) REFERENCES ec2_servers(ec2_server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
