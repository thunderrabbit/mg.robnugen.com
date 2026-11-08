-- What a sweep found about one hostname, open from the sweep that first saw
-- these facts until the sweep that saw different ones (until_sweep_id NULL =
-- current), the shape of dh_domain_states. A sweep that finds the same facts
-- opens nothing; two nulls are the same fact.
--
-- php_version is the PHP that answers web requests for the hostname, read by
-- asking the server for /_php_version.php over loopback; probe_route says
-- which way in worked (direct TLS, or plain HTTP with X-Forwarded-Proto behind
-- a load balancer). NULL means the sweep could not read it, never "none".
-- deployed_at_utc is the modification time of the directory path itself (a
-- `current` symlink's own time), the clue a non-git deploy leaves.

CREATE TABLE ec2_domain_states (
    ec2_domain_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ec2_domain_id       INT UNSIGNED NOT NULL,
    from_sweep_id       INT UNSIGNED NOT NULL,
    until_sweep_id      INT UNSIGNED NULL,
    php_version         VARCHAR(16)  NULL,
    probe_route         ENUM('https','http_forwarded') NULL,
    directory_exists    TINYINT(1)   NOT NULL,
    is_git_checkout     TINYINT(1)   NOT NULL,
    head_commit         CHAR(40)     NULL,
    head_branch         VARCHAR(100) NULL,
    deployed_at_utc     DATETIME     NULL,
    UNIQUE KEY uq_ec2_domain_from (ec2_domain_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (ec2_domain_id)  REFERENCES ec2_domains(ec2_domain_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES ec2_sweeps(ec2_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES ec2_sweeps(ec2_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
