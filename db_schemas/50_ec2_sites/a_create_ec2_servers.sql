-- A server that is not on DreamHost: a machine reached by an ssh alias. The
-- name says EC2 because every one of them is on EC2 today; nothing here is
-- specific to AWS. Rows are entered by hand (site.sh ec2-server-add).
--
-- ssh_alias is a Host name in the ssh config on Lemur 13: a pointer, never a
-- credential. login_confirmed_on is written ONCE, by the importer, when the
-- first sweep of the server arrives, and never refreshed; the writer guards
-- with `AND login_confirmed_on IS NULL`, as dh_users does.

CREATE TABLE ec2_servers (
    ec2_server_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ssh_alias          VARCHAR(64)  NOT NULL,
    label              VARCHAR(100) NOT NULL DEFAULT '',
    login_confirmed_on DATE         NULL,
    UNIQUE KEY uq_ec2_server_alias (ssh_alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
