-- DreamHost moves a MySQL server to another machine now and then (5b to 5a on
-- 2026-11-07) and the panel names the server "<machine>:<dreamhost user>".
-- The server is the DreamHost user; the machine is a span on it, so a move
-- closes one span and opens another and nothing else, databases and Rob's
-- triage calls included, gets a new identity (mg #803).

CREATE TABLE dh_mysql_machines (
    dh_mysql_machine_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dh_mysql_server_id  INT UNSIGNED NOT NULL,
    machine             VARCHAR(64)  NOT NULL,
    from_sweep_id       INT UNSIGNED NOT NULL,
    until_sweep_id      INT UNSIGNED NULL,
    UNIQUE KEY uq_server_from (dh_mysql_server_id, from_sweep_id),
    INDEX idx_open (until_sweep_id),
    FOREIGN KEY (dh_mysql_server_id) REFERENCES dh_mysql_servers(dh_mysql_server_id),
    FOREIGN KEY (from_sweep_id)      REFERENCES dh_sweeps(dh_sweep_id),
    FOREIGN KEY (until_sweep_id)     REFERENCES dh_sweeps(dh_sweep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Each existing server keeps its row and id; its machine becomes the first span.
INSERT INTO dh_mysql_machines (dh_mysql_server_id, machine, from_sweep_id, until_sweep_id)
SELECT dh_mysql_server_id, SUBSTRING_INDEX(name, ':', 1), first_seen_sweep_id, gone_in_sweep_id
FROM dh_mysql_servers
WHERE name LIKE '%:%';

UPDATE dh_mysql_servers SET name = SUBSTRING_INDEX(name, ':', -1) WHERE name LIKE '%:%';
