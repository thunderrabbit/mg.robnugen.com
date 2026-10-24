-- One identity per domain NAME, however it is held: a Manage Websites row
-- (dh_domain_states), a certificate (dh_domain_certs), a DreamHost
-- registration (dh_registration_states), or several at once. Certificates are
-- versioned apart because Let's Encrypt renews every ~60 days.
--
-- base_domain is DERIVED from domain (public-suffix list) and fixed for life;
-- stored for grouping. registered_on is the first registration date and is set
-- only when DreamHost registers the name.

CREATE TABLE dh_domains (
    dh_domain_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_account_id INT UNSIGNED NOT NULL,
    domain        VARCHAR(253) NOT NULL,
    base_domain   VARCHAR(253) NOT NULL,
    registered_on DATE         NULL,
    UNIQUE KEY uq_account_domain (dh_account_id, domain),
    INDEX idx_base_domain (base_domain),
    FOREIGN KEY (dh_account_id) REFERENCES dh_accounts(dh_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- `dns` is the panel's plain "DNS" type. dh_user_id is the owner (hosted only);
-- being a stable id, ownership history is a real query.
CREATE TABLE dh_domain_states (
    dh_domain_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_domain_id       INT UNSIGNED NOT NULL,
    from_sweep_id      INT UNSIGNED NOT NULL,
    until_sweep_id     INT UNSIGNED NULL,
    type               ENUM('hosted','redirect','mirror','dns','dns_only','parked') NOT NULL,
    dh_user_id         INT UNSIGNED NULL,
    server             VARCHAR(64)  NULL,
    php_version        VARCHAR(8)   NULL,
    web_directory      VARCHAR(255) NULL,
    UNIQUE KEY uq_domain_from (dh_domain_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    INDEX idx_user (dh_user_id),
    FOREIGN KEY (dh_domain_id)   REFERENCES dh_domains(dh_domain_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (dh_user_id)     REFERENCES dh_users(dh_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- 'temporary' is DreamHost's self-signed certificate.
CREATE TABLE dh_domain_certs (
    dh_domain_cert_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_domain_id      INT UNSIGNED NOT NULL,
    from_sweep_id     INT UNSIGNED NOT NULL,
    until_sweep_id    INT UNSIGNED NULL,
    https             ENUM('lets_encrypt','temporary','none') NOT NULL,
    cert_expires      DATE         NULL,
    UNIQUE KEY uq_domain_from (dh_domain_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_domain_id)   REFERENCES dh_domains(dh_domain_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- A renewal moves expires, so each renewal opens a new state and leaves a trail.
CREATE TABLE dh_registration_states (
    dh_registration_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_domain_id             INT UNSIGNED NOT NULL,
    from_sweep_id            INT UNSIGNED NOT NULL,
    until_sweep_id           INT UNSIGNED NULL,
    registry                 VARCHAR(32)  NULL,
    expires                  DATE         NOT NULL,
    auto_renew               TINYINT(1)   NOT NULL,
    auto_renew_on            DATE         NULL,
    transfer_lock            TINYINT(1)   NOT NULL,
    privacy                  TINYINT(1)   NOT NULL,
    status                   VARCHAR(32)  NOT NULL,
    hidden                   TINYINT(1)   NOT NULL,
    UNIQUE KEY uq_domain_from (dh_domain_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_domain_id)   REFERENCES dh_domains(dh_domain_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
