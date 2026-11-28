# A database opener for after phpMyAdmin

2026-11-27. Rewritten the same day to match what was built: the first
version of this document described a larger Python tool, and Rob steered to
a small script grown from the old `phpmyadmin-open.sh`.

## Intent

Rob opens a site's database to tweak a value or run SQL. He did it with a
per-site `phpmyadmin-open.sh`, which read the database password from the
server's config class and signed him in to DreamHost's phpMyAdmin. DreamHost
has retired phpMyAdmin (the panel says so, and points to a desktop app).

He needs another way in, on any DreamHost site. And the sites are moving
their config from a class file to the `site_config.php` array
(`2026-11-26-site-config-steps-design.md`), so the way in must work before
and after a site's move, with nothing to change at the move.

## Goals

- One command, typed anywhere inside a site's checkout on Lemur 13, opens
  DBeaver connected to that site's production database.
- It works for a site on `site_config.php` and for a site still on its old
  config class, without being told which.
- Nothing new on Lemur 13 that another machine, or a web page in Rob's
  browser, can reach.
- The password is never on a command line, never printed, and not kept.

## Non-goals

- Sites on EC2, test domains' databases, opening from a phone, a read-only
  mode, and a database tool of our own.

## Approaches

**Which tool.** phpMyAdmin run on the laptop was rejected: a web app on
`127.0.0.1` can be reached by a page in Rob's own browser, its last release
was October 2025, and a report of October 2026 lists seven unfixed flaws,
one of that kind. MySQL Workbench was rejected: its 8.0 line is unmaintained
and has an open bug crashing on MySQL 8.4, which DreamHost runs. **DBeaver
Community was chosen by Rob**: a desktop app that listens on nothing.

**A copy per site, or one script.** The sixteen per-site copies had drifted
into thirteen versions. **One script in mg, chosen**: mg already knows each
site's ssh alias and directory.

**How Rob starts it.** A file in each site named to tab-complete was
considered. **A shell function was chosen by Rob**: `qlscript_opener` in
his `~/.bash_aliases`, which `ql[TAB]` completes to because no other command
on Lemur 13 starts with `ql`. No site needs a file.

**How it reaches the database.** Naming Lemur 13 in the panel's "Allowable
Hosts" was rejected: its address is unstable. **An ssh tunnel to the site's
server was chosen**; Rob showed on 2026-11-27 that the database accepts a
login that arrives that way, with nothing changed in the panel.

**How the password reaches DBeaver.** On the command line was rejected (`ps`
shows it). **DBeaver's `-vars` file was chosen by Rob**, over an environment
variable, because it is the documented route and should also work when
DBeaver is already running.

## What was built

`tools/db-open/db-open.sh`; mg's `CLAUDE.md` describes it under "Opening a
site's database". In order, it:

1. asks mg which site the checkout is and where its server is
   (`site.sh next-step`), and stops for a checkout that is no site, a site
   with no server link, or a site not on DreamHost;
2. pipes a short PHP script over ssh to the served PHP, which reads the four
   values from `site_config.php`, else `classes/Config/Config.php`, else
   `classes/Config.php`, at the web directory or up to two above it;
3. opens the tunnel on `127.0.0.1`, or uses the one already open for the
   site;
4. writes the password to a mode-600 file in `$XDG_RUNTIME_DIR`, starts
   DBeaver with `-vars` and a temporary connection, and removes the file
   after 90 seconds.

`--mysql` opens the mysql client the same way, `--dry-run` stops after 2 and
names no value, `--close` closes the site's tunnel.

Rob runs everything that opens a production database. A session runs the
tests and `--dry-run`.

## Shown working

- mg, on its old class in the root namespace, and a site already on
  `site_config.php`: Rob opened both in DBeaver on 2026-11-27 with the
  per-site scripts this one grew from, and `--dry-run` of this script reads
  both.
- A test pins the conversion: one made-up server holding an old class, then
  the same values in `site_config.php`; DBeaver is started identically.

## Not yet done

- **The old `phpmyadmin-open*.sh` files** in each site: the Tooling step
  `phpmyadmin_script_reminds` turns each into one `echo` saying to type
  `ql[TAB]`. mg and one other site have done it. The step
  `phpmyadmin_script_removed` deletes the reminders, and is an idea until
  2027-01-27 at the earliest.
- A step that runs `--dry-run` for every site was considered and dropped
  (Rob, 2026-11-27): the opener worked in a third site unchanged.
- **Checking what DBeaver keeps**: after a session, whether anything under
  `~/.local/share/DBeaverData/` holds the temporary connection.
- **A tunnel that closes itself.** Today it stays until `--close`.
