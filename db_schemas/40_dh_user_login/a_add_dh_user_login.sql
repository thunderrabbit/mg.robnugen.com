-- How to log in as a DreamHost user (mg #780). Two nullable columns on the
-- identity row, filled once when /recover-dh-user proves a login works and
-- never by a sweep, so the importer leaves them alone and a rebuild keeps them.
--
-- ssh_alias is the Host name in the local ssh config (e.g. b.rn): a pointer,
-- never a credential. login_confirmed_on is written ONCE and never refreshed;
-- if the login fails years later it hints when it last worked. The writer
-- guards with `AND login_confirmed_on IS NULL`.

ALTER TABLE dh_users
    ADD COLUMN ssh_alias          VARCHAR(64) NULL AFTER username,
    ADD COLUMN login_confirmed_on DATE        NULL AFTER ssh_alias;
