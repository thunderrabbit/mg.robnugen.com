# A database opener for after phpMyAdmin

2026-11-27.

## Intent

Rob opens a site's database to tweak a value or run SQL. He did it with a
per-site `phpmyadmin-open.sh`, which read the database password from the
server's config class and signed him in to DreamHost's phpMyAdmin. DreamHost
has retired phpMyAdmin (the panel says so, and points to a desktop app).

Two things follow. He needs another way in, on any DreamHost site. And the
sites are moving their config from a class file to the `site_config.php`
array (`2026-11-26-site-config-steps-design.md`), which the old scripts
cannot read; the way in must work before, during and after a site's move.

Rob chose DBeaver Community, a desktop app, over a phpMyAdmin run on the
laptop and over MySQL Workbench (see Approaches).

## Goals

- One command, run inside a site's checkout on Lemur 13, opens DBeaver
  connected to that site's production database, ready to browse, edit rows
  and run any SQL.
- It works for a site on `site_config.php`, for a site still on its old
  config class, and for mg's own old class, with no setting to say which.
  Converting a site changes nothing for the person running it.
- Nothing new on Lemur 13 that another machine, or a web page in Rob's
  browser, can reach.
- The database password is on the laptop only while a connection is being
  opened, never saved by DBeaver and never on a command line.

## Order of work

Rob set it:

1. A site already on `site_config.php` first.
2. Then mg, which is still on its old class.
3. Then mg takes the config steps, and the opener is shown to work the same
   afterwards with no change.

Step 3's conversion is the other design's practice run. This bubble ends
with 1 and 2 working and a test that pins 3.

## Non-goals

- Sites on EC2. The opener says it handles DreamHost sites only.
- A test domain's database. Production only, for now.
- Removing the per-site `phpmyadmin-open.sh` files. They live in other
  repos and each repo's own session removes its copy; the template's copy is
  the template session's. This design only makes them unnecessary.
- Opening from a phone.
- Writing a database tool of our own. Rob is about 70% against; DBeaver gets
  a trial first.
- A read-only mode. He goes there to change things.

## Anti-goals

- A password in any output, log, shell history, process list (`ps`), commit,
  or file that outlives the opening of the connection.
- A listening port on any address but `127.0.0.1`.
- A password saved in DBeaver's own files. Its Community edition keeps saved
  passwords in a local file without real protection.
- A site's name, host name or ssh alias in this public repo.

## Constraints

- Lemur 13 is Pop!_OS 22.04. Its own `php` is 8.1; the opener's logic is
  Python, like `next_step.py`.
- The opener runs on Lemur 13 and reaches a server by the ssh alias and
  directory mg holds for the site, found the way `next-step.sh` finds them
  (`site.sh next-step` over `ssh mg`, from the checkout's path).
- On a DreamHost server the shell's `php` is old; the served PHP is
  `/usr/local/php<NN>/bin/php`.
- A database host accepts connections from the DreamHost servers, as the
  site's own does. The tunnel makes the connection come from there, so the
  panel's "Allowable Hosts" should need no entry for Rob's own address.
  This is believed, not yet shown; proving it is the first task.
- DreamHost's database server is MySQL 8.4.
- The script never writes on a server and never writes in a checkout.

## Approaches

**Which tool.** Three were put to Rob:

- phpMyAdmin run on the laptop in Docker, through an ssh tunnel. The screens
  he knows. But it is a web app on `127.0.0.1`, which his own browser can be
  made to talk to by a page he visits; its last release was October 2025;
  and a report of October 2026 lists seven unfixed flaws, one of exactly
  that kind. Rejected.
- MySQL Workbench, which DreamHost names. The 8.0 line is no longer
  maintained and has an open bug crashing on MySQL 8.4; whether its
  successor installs on 22.04 could not be confirmed. Rejected.
- DBeaver Community. Released days ago, a desktop app that listens on
  nothing, grid editing and a SQL editor. **Chosen by Rob.**

A phpMyAdmin or Adminer hosted on one of the sites was ruled out earlier: a
database admin page on the public web is what bots hunt for.

**Where the opener lives** (my call):

- A copy in each site, as `phpmyadmin-open.sh` was. Rejected: sixteen copies
  drifted into thirteen versions.
- In the template, pulled by each site. Rejected for the same reason, and mg
  is not built from the template.
- One script in mg, `tools/db-open/`, run from inside any site's checkout.
  mg already knows each site's ssh alias and directory. **Chosen.**

**How the credentials are read** (my call):

- `grep` and `sed` over the config file, as the old scripts did. Rejected:
  it breaks on quoting, and each config shape needs its own pattern.
- Through `Config\Loader` on the server. Rejected: mg has no loader until it
  converts, and the loader checks every key, so one unrelated bad key would
  lock Rob out of the very tool he would fix it with.
- A short PHP script piped over ssh to the server's PHP, which asks PHP
  itself for the four values. **Chosen**; see below.

**How DBeaver reaches the database** (my call):

- DBeaver's built-in ssh tunnel. Rejected: it needs the ssh host, user and
  key entered per site and saved in DBeaver, and it does not read
  `~/.ssh/config`, where the aliases live.
- The opener starts the tunnel with the system's `ssh` and the site's alias,
  and DBeaver connects to `127.0.0.1`. **Chosen.**

**How DBeaver is installed** (my call, Rob may overrule): from DBeaver's own
apt repository, so it updates with the rest of the system and is the
vendor's build. Rob runs the install, since it needs `sudo`. The Flathub
package says it is not the vendor's and is unverified; the tarball needs no
`sudo` but never updates.

## The command

`tools/db-open/db-open.sh`, logic in `db_open.py`, run from inside a site's
checkout:

    ~/work/rob/mg.robnugen.com/tools/db-open/db-open.sh [--dry-run] [--shortcode=<code>]

What it does, in order:

1. Finds the site, its ssh alias and its server directory as `next_step.py`
   does. Stops with one sentence when the checkout is no site on mg's page,
   the site has no server link, or the server is not a DreamHost one.
2. Reads the four values (`dbHost`, `dbName`, `dbUser`, `dbPass`) from the
   server (below). Stops, naming the missing key and never a value, when one
   is missing or empty.
3. Opens the tunnel: the system `ssh`, to the site's alias, forwarding a
   free port on `127.0.0.1` to `dbHost:3306`, failing at once when the
   forward cannot be made.
4. Starts DBeaver with a connection named for the site's shortcode, on
   `127.0.0.1` and that port, as `dbUser` on `dbName`, connected, with a SQL
   console open. The connection is a temporary one: DBeaver's `save=false`.
5. Says one line: which site, which database, which config file the values
   came from (`site_config.php` or the old file's path). Never a value
   other than the database's name.

`--dry-run` does 1 and 2 and says what it would open; it starts no tunnel
and no DBeaver.

### Reading the credentials

One PHP script, kept in `tools/db-open/` and piped over ssh to the served
PHP on the site's server, with the project root as its argument. The project
root is the git top level of the server directory, since a site's web
directory may be a subdirectory of its checkout. It:

1. If `site_config.php` is in the project root, `require`s it and takes the
   array it returns.
2. Otherwise looks for the old file, in this order: the path and class the
   server's own `classes/Config/Loader.php` names in `LEGACY_FILE` and
   `LEGACY_CLASS`, when the server has a loader; then
   `classes/Config/Config.php` with class `Config\Config`; then
   `classes/Config.php` with class `Config`. It `require`s the first one
   that exists and takes the public properties of a new object of the class.
   The loader is read as text, never loaded (it checks every key): the
   template writes both constants as plain single-quoted strings, the path
   relative to the project root. A loader whose constants are written any
   other way is passed over, and the two fixed places are tried.

Those three places and the four key names are all the opener knows (my
call). A site that keeps its old file elsewhere, or names its keys
otherwise, gets a stop saying where it looked; it gains the opener when it
converts, which is where every site is headed. No survey of the old
per-site scripts is part of this work.
3. Prints one JSON object: the four values and which file they came from.
   A file that does not parse reports its line number only, since PHP's own
   message quotes the code, and that may be the password.

The order in 1 and 2 is the loader's own, so the opener and the site always
agree on which file is the config. This is the whole of "keeps working after
the conversion": before it the script finds the old file, after it the new
one, and the opener is not told which.

The JSON goes from ssh's stdout into the Python process and nowhere else.

### The tunnel

- Bound to `127.0.0.1` only, on a port the opener picks free.
- It closes by itself once DBeaver has let go of the connection; nothing is
  left listening after the work is done. How (ssh's own behaviour with a
  short-lived remote command, or the opener waiting) is the builder's.
- What can reach it while open: programs run as Rob on Lemur 13. It speaks
  the MySQL protocol and still asks for the password, so a web page cannot
  use it.

### Handing DBeaver the password

DBeaver's `-con` parameter takes a password, but a command line shows in
`ps`. Its documentation gives two other routes: a properties file named by
`-vars`, and environment variables, either one referred to as `${name}`
inside `-con`. The requirement is fixed and the route is the builder's to
choose by trying both:

- the password is never an argument of any process;
- if a file is used it is made mode 600 under `$XDG_RUNTIME_DIR` (memory,
  not disk) and removed as soon as DBeaver has connected;
- after DBeaver is closed, the password is in none of DBeaver's files.
  `--leftovers` (below) checks this on the real thing by searching its data
  directory for the value without printing it;
- it must work both when DBeaver is not yet running and when it is, since a
  second site is often opened beside the first.

Both routes are built. If neither meets all four on the installed DBeaver,
the finding goes to Rob; there is no fallback to the command line.

### Output

One line for a person, like the checks'. Sentences are constants at the top
of `db_open.py`, pinned word for word in tests, as `next_step.py`'s are.

## Proving it (the order Rob set)

**Rob runs every step that touches a real database** (his choice,
2026-11-27). The session builds and tests against a fake ssh and a stand-in
for DBeaver, and never holds a production password. So each live step below
is a command Rob runs that answers in one line, and two things exist for
his runs:

- `--password-route=vars|env` picks how DBeaver is handed the password,
  since which one works can only be seen on the real DBeaver. The default is
  `vars`; the one that passes his run becomes the default, and the other
  stays as the option.
- `--leftovers`, run after DBeaver is closed, reads the password from the
  server again and searches DBeaver's data directory for it. It says clean,
  or names the file that holds it, never the value.

0. **The tunnel is allowed, before anything is built on it.** The database's
   allow-list is per user and host, so only a real login shows it. The
   reader and the tunnel are built first, with a `--probe` option that logs
   in through the tunnel with the `mysql` client already on Lemur 13, the
   password handed to it in a mode-600 file under `$XDG_RUNTIME_DIR` that is
   removed straight after, and runs `SELECT 1`. It says allowed or refused
   and nothing else. Rob changes nothing in the panel beforehand; a refusal
   is the finding, "Allowable Hosts" is looked at with him, and the rest
   waits. `--probe` stays in the tool: it answers "is it the database or is
   it DBeaver" later.
1. **A site already on `site_config.php`** (Rob named it in the session).
   Rob installs DBeaver. The opener is run in that site's checkout:
   `--dry-run`, `--probe`, then for real.
2. **mg**, on its old class in the root namespace: the third place the
   reader looks.
3. **mg after its conversion** (the other design's practice run, later):
   the same command, and the line now names `site_config.php`.

## Tests

`python3 -I -m unittest discover -s tools/db-open/tests`, with a fake ssh as
the site-standards tests use (`SCAN_SSH`), and DBeaver replaced by a stub
that records how it was started:

- each stop and its sentence;
- the reader finds the values in each of the four places, in the order
  above, and prefers `site_config.php` when both it and an old file exist;
- **the conversion is pinned:** one fake server holding an old class, then
  the same server holding `site_config.php` with the same values; the two
  runs start DBeaver identically but for the line naming the file;
- a planted password appears in no output and in no argument of any
  process the opener starts;
- `--dry-run` starts nothing;
- the PHP reader itself is run by the tests against fixture directories,
  on the standards runner image, never on Lemur 13's PHP 8.1.

## Documentation

A short section in mg's `CLAUDE.md`, after "Site standards": what the
command is, where the password does and does not go, and that the per-site
phpMyAdmin scripts are dead.

## Left to the builder

- Sharing code with `next_step.py` and `scan.py` (finding the site, running
  ssh) rather than copying it.
- How the tunnel closes by itself, and what DBeaver setting, if any, keeps
  an idle connection from dropping.
- The DBeaver driver name and connection properties for MySQL 8.4.
- The pinned sentences.
- Commit layout inside the bubble.

## Sources read for the choice of tool

- phpMyAdmin's home and security pages (5.2.3, 2025-10-08; no 2026
  advisory) and a security news report of 2026-10-30 (seven flaws, none
  fixed). The report is one source and was not checked against each CVE.
- MySQL bug 120661 (Workbench 8.0.46 crashes on 8.4 connections).
- DBeaver's download page (26.2.5.2, 2026-11-24) and its Command-Line,
  Admin-Variables and Secure-storage documentation.
