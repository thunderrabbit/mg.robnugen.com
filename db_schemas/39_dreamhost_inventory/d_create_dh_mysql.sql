-- MySQL servers, the phpMyAdmin hostnames that point at them, databases, and
-- which MySQL users reach which databases.
--
-- dh_mysql_hosts are DreamHost-controlled DNS pointers: NEVER upgrade or
-- health-sweep targets. A row says "hostname H pointed at server S" for a span.
-- Servers and MySQL users have no attributes of their own, so their lifetime
-- sits on the identity row: first_seen_sweep_id, and gone_in_sweep_id = the
-- first sweep they were absent from (NULL while present).

CREATE TABLE dh_mysql_servers (
    dh_mysql_server_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_account_id       INT UNSIGNED NOT NULL,
    name                VARCHAR(64)  NOT NULL,
    first_seen_sweep_id INT UNSIGNED NOT NULL,
    gone_in_sweep_id    INT UNSIGNED NULL,
    UNIQUE KEY uq_account_name (dh_account_id, name),
    FOREIGN KEY (dh_account_id)       REFERENCES dh_accounts(dh_account_id),
    FOREIGN KEY (first_seen_sweep_id) REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (gone_in_sweep_id)    REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_mysql_hosts (
    dh_mysql_host_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_mysql_server_id INT UNSIGNED NOT NULL,
    hostname           VARCHAR(253) NOT NULL,
    from_sweep_id      INT UNSIGNED NOT NULL,
    until_sweep_id     INT UNSIGNED NULL,
    UNIQUE KEY uq_hostname_from (hostname, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_mysql_server_id) REFERENCES dh_mysql_servers(dh_mysql_server_id),
    FOREIGN KEY (from_sweep_id)      REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id)     REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_databases (
    dh_database_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_mysql_server_id INT UNSIGNED NOT NULL,
    name               VARCHAR(64)  NOT NULL,
    UNIQUE KEY uq_server_name (dh_mysql_server_id, name),
    FOREIGN KEY (dh_mysql_server_id) REFERENCES dh_mysql_servers(dh_mysql_server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- description is NULL when the panel's text hinted at a password.
CREATE TABLE dh_database_states (
    dh_database_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_database_id       INT UNSIGNED NOT NULL,
    from_sweep_id        INT UNSIGNED NOT NULL,
    until_sweep_id       INT UNSIGNED NULL,
    description          VARCHAR(255) NULL,
    UNIQUE KEY uq_database_from (dh_database_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_database_id) REFERENCES dh_databases(dh_database_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_database_usage (
    dh_database_usage_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_database_id       INT UNSIGNED    NOT NULL,
    dh_sweep_id          INT UNSIGNED    NOT NULL,
    size_bytes           BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_database_sweep (dh_database_id, dh_sweep_id),
    FOREIGN KEY (dh_database_id) REFERENCES dh_databases(dh_database_id),
    FOREIGN KEY (dh_sweep_id)    REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_db_users (
    dh_db_user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_mysql_server_id  INT UNSIGNED NOT NULL,
    username            VARCHAR(64)  NOT NULL,
    first_seen_sweep_id INT UNSIGNED NOT NULL,
    gone_in_sweep_id    INT UNSIGNED NULL,
    UNIQUE KEY uq_server_username (dh_mysql_server_id, username),
    FOREIGN KEY (dh_mysql_server_id)  REFERENCES dh_mysql_servers(dh_mysql_server_id),
    FOREIGN KEY (first_seen_sweep_id) REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (gone_in_sweep_id)    REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Many-to-many: claude_cf_ro reaches games_cf and gamet_cf; games_cf has four users.
CREATE TABLE dh_db_grants (
    dh_db_grant_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_database_id INT UNSIGNED NOT NULL,
    dh_db_user_id  INT UNSIGNED NOT NULL,
    from_sweep_id  INT UNSIGNED NOT NULL,
    until_sweep_id INT UNSIGNED NULL,
    UNIQUE KEY uq_grant_from (dh_database_id, dh_db_user_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_database_id) REFERENCES dh_databases(dh_database_id),
    FOREIGN KEY (dh_db_user_id)  REFERENCES dh_db_users(dh_db_user_id),
    FOREIGN KEY (from_sweep_id)  REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id) REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
