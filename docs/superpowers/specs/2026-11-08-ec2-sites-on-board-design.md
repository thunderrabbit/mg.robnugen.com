# Sites that are not on DreamHost, on the standards board

Design for mg 0.67.0, branch `add-ec2-sites`. Decided with Rob on 2026-11-08;
the UI choice was made from three mockups.

## Intent

The site-standards board (`/admin/dh/standards.php`) drives library and PHP
upgrades for every site Rob maintains, measured against written-up steps. Today
it can only reach a site whose server is a DreamHost domain, because a site's
server and test domain are found through `dh_site_domains` and the DreamHost
sweep. Three of Rob's sites live on EC2 instead: two on a client's AWS account
(the ones whose eight-month jQuery upgrade shaped the JavaScript track) and one
on his own. They should appear on the same board, measured by the same steps,
so that a person who is not Rob can read their next step there without knowing
or caring which host a site is on.

## Goals

- An EC2 site is registered, linked to the hostnames that serve it, scanned,
  and shown on the board with the same marks and the same footer facts as a
  DreamHost site.
- The five steps mg answers from host facts (has a domain, ssh login works,
  serves the target PHP, test domain serves the target PHP, repo located) are
  answered for EC2 sites from facts gathered deterministically over ssh.
- The PHP version recorded for an EC2 hostname is the one that serves web
  requests, read through the web stack itself, never `php -v`.
- Every existing check script runs unchanged: the scanner fills
  `SITE_SERVER_*` and `SITE_TEST_*` for an EC2 site the way it does for a
  DreamHost one.
- Nothing about the DreamHost pages or the DreamHost sweep changes.

## Non-goals

- No AWS API access. Lemur 13 has none to the client's account and needs none:
  everything is asked over ssh aliases that already exist in Rob's ssh config.
- No EC2 inventory pages (`/admin/ec2/…`). EC2 servers and hostnames are
  entered by CLI and shown only through the standards board.
- No discovery of vhosts. The sweep probes the hostnames Rob entered; it does
  not parse web-server configuration to find them.
- No change to how `js_soaked_on_production` learns a deploy time on a server
  that is not a git checkout. The sweep records what it can see (below); using
  it is a later step.

## Anti-goals

- Warning styling, a fourth mark, or anything that makes an unreachable or
  half-entered EC2 site look urgent. Every site here is working.
- A board that needs the reader to know the host before finding a site's next
  step.
- Real hostnames, aliases or shortcodes in step files, tests, comments or this
  document. This repo is public; those facts live in the database.
- Guessing the web PHP version from the CLI one, or passing a serves-PHP step
  on a hostname the sweep could not read.

## What was learned on a real server (the spike)

On 2026-11-08 Rob asked for the loopback idea to be tried on one of the client
EC2 servers, against its test hostname, with read-only commands:

- TLS ends at a load balancer. The instance's port 443 speaks plain HTTP and
  serves a health-check file; the named vhosts are all on port 80. So
  `curl --resolve host:443:127.0.0.1 https://host/` fails with a TLS error.
- Plain HTTP on port 80 with `Host: <hostname>` and
  `X-Forwarded-Proto: https` reaches the real vhost: the vhost's rewrite rule
  redirects to https unless that header is present, and with it PHP answered
  (the app's own session cookie and its usual redirect came back).
- `expose_php = Off`, so no `X-Powered-By` header. A probe file is needed.
- The vhost sets `php_value auto_prepend_file …/prepend.php`, so any probe
  file runs after the site's own bootstrap. The site must let the probe path
  through.
- CLI PHP and web PHP happened to match on that box. They do not in general,
  which is why the probe exists.

## The shape

### A server, its hostnames, and a sweep (new tables, `db_schemas/50_ec2_sites/`)

Table names use `ec2_` because all three sites are on EC2 today. Nothing in
them is specific to AWS: a server is a machine reached by an ssh alias.

- `ec2_servers`: `ec2_server_id`, `ssh_alias` (unique; a Host name in the ssh
  config on Lemur 13, a pointer, never a credential), `label` (free text for
  the page, e.g. whose account it is), `login_confirmed_on DATE NULL`.
  `login_confirmed_on` is written once, by the importer, the first time a
  sweep of that server arrives, and never refreshed: the same rule as
  `dh_users.login_confirmed_on`.
- `ec2_domains`: `ec2_domain_id`, `ec2_server_id`, `domain` (unique),
  `directory` (absolute path of the checkout that serves it, e.g.
  `/var/www/example.test/current`). Entered by hand.
- `ec2_sweeps`: `ec2_sweep_id`, `ec2_server_id`, `swept_at_utc`, and the
  server-level facts the script reports (`web_server`, `cli_php`), kept for
  the footer line and for contrast with the web PHP.
- `ec2_domain_states`: `ec2_domain_state_id`, `ec2_domain_id`,
  `from_sweep_id`, `until_sweep_id NULL`, `php_version NULL`,
  `probe_route ENUM('https','http_forwarded') NULL`, `directory_exists`,
  `is_git_checkout`, `head_commit CHAR(40) NULL`, `head_branch NULL`,
  `deployed_at_utc NULL`. Open row = `until_sweep_id IS NULL`, the same shape
  as `dh_domain_states`, so the board and `forScanner` read both kinds with
  near-identical queries. A sweep that finds the same facts closes nothing
  and opens nothing; two nulls are the same fact, so a hostname the probe
  still cannot read keeps its open row.
- `ec2_site_domains`: `ec2_site_domain_id`, `std_site_id`, `ec2_domain_id`,
  `path_prefix` (default `''`, same meaning as in `dh_site_domains`).
- `std_sites` gains `server_ec2_site_domain_id` and `test_ec2_site_domain_id`,
  nullable foreign keys into `ec2_site_domains`, beside the two DreamHost
  ones. `std_sites` itself still says nothing about hosting.

Rule, enforced in `SiteStore`: a site's links are all of one kind. Linking an
EC2 hostname to a site that has a DreamHost link is refused with a sentence,
and the reverse. `TestDomainRule` applies within a kind as before.

### Entering facts (CLI only, `tools/site-standards/site.sh`)

New commands, in `SiteCommand` and `SiteStore`:

- `ec2-server-add --alias=<ssh alias> [--label="…"]`
- `ec2-domain-add --alias=<ssh alias> --domain=<hostname> --directory=</abs/path>`
- `ec2-servers`: JSON for the sweep: every server with its alias and its
  hostnames and directories.

Existing commands learn the second kind without new flags:

- `site-link --shortcode=<code> --domain=<hostname>` looks the hostname up in
  `dh_domains` and then in `ec2_domains`; found in neither, "No DreamHost
  domain or EC2 hostname is called …"; found in both, refused and named.
- `site-set --server=<hostname>[<path>]` and `--test=…` name a link of either
  kind. `site-unlink` likewise.
- `site-set --upgrade=`, `--checkout=`, `--github=`, `--project=`,
  `--autonomy=` are unchanged.

### The sweep (`tools/ec2-sweep/`)

Two scripts, versioned in mg, run from Lemur 13:

- `deets.sh` is the server-side script. It is installed on each server by
  `install.sh <alias>` as a pinned copy (the journal-sync pattern: changing
  the file in mg does nothing on a server until `install.sh` is run again),
  at `~/.local/bin/sweep_ec2_deets_for_mg.sh`. It is invoked by name with
  `hostname=directory` pairs as arguments, so a dedicated ssh key with a
  forced command can pin it later; today it runs under the server's ordinary
  alias. It reads only: it never writes to a repo, a docroot or a config.
  For each pair it reports:
  - the web PHP version, by asking the server itself for `/_php_version.php`
    on that hostname. It tries `https://127.0.0.1` with
    `--resolve <hostname>:443:127.0.0.1` first, then plain
    `http://127.0.0.1:80/` with `Host: <hostname>` and
    `X-Forwarded-Proto: https`. The first route whose body is a bare PHP
    version string wins and is recorded as `probe_route`; otherwise
    `php_version` is null and the reason (TLS error, redirect, 404, body not a
    version) is in the summary line.
  - whether the directory exists, whether it is a git checkout, and if so
    `HEAD`'s commit and branch (`git --no-optional-locks`, like the scanner).
  - `deployed_at_utc`: the modification time of the directory's last path
    component (for a `current` symlink, the link itself), the best
    deterministic clue a non-git deploy leaves. Null when unreadable.
  - server-level: `web_server` from the `Server` header of the first answer,
    `cli_php` from `php -v`, for the footer and for contrast.
  Output is one JSON object on stdout; nothing else goes to stdout.
- `sweep.sh [--dry-run] [alias …]` on Lemur 13 asks mg for `ec2-servers`,
  runs `deets.sh` on each server over `ssh -o BatchMode=yes`, and streams each
  result to `ssh mg php tools/import_ec2_sweep.php`, which reads STDIN and
  imports inside a transaction (`--dry-run` rolls back and prints the
  summary), the same contract as `import_dh_sweep.php`. The importer
  validates shape before touching tables and refuses a document for a server
  or hostname mg does not know. Nothing schedules the sweep yet; Rob runs it,
  as he runs the DreamHost one.

### The probe file (each site's own repo)

`wwwroot/_php_version.php` prints `PHP_VERSION` and nothing else when
`REMOTE_ADDR` is `127.0.0.1` or `::1`, and answers 404 otherwise. Behind a
load balancer, public requests arrive from the balancer's address, so the
loopback test still excludes them. A site whose bootstrap runs on every
request (auto-prepend, front controller, auth redirect) must let this path
through before the bootstrap acts; that is the site's job and is what the new
step below asks for.

mg itself gets the file too, so the probe is exercised on a DreamHost site
and so mg's own row can prove the step.

### Answering the five host steps (`HostChecks`)

`SiteFacts` gains the host kind and, for EC2 sites, the server's
`login_confirmed_on` and each linked hostname's open state. `HostChecks`
answers the same step from whichever kind the site has:

| step file check | DreamHost (unchanged) | EC2 |
|---|---|---|
| `dh_has_domain` | a linked domain exists | a linked hostname exists |
| `dh_ssh_login` | every serving user has `login_confirmed_on` | the server has `login_confirmed_on` (a sweep has succeeded) |
| `dh_serves_php` | panel's `php_version` ≥ wanted | open state's `php_version` ≥ wanted; null → Unknown, "the sweep could not read which PHP serves <hostname>" |
| `dh_test_serves_php` | same for the test domain | same for the test hostname |
| `repo_located` | host-neutral | unchanged |

The `dh_` prefix in those check names is now historical: it means "answered by
mg from host facts". Builder's call: keep the names (step files are deployed
and public; a rename would need aliases for one deploy and buys only
tidiness).

A new step in the `template` standard's `groundwork` track,
`web_php_version_readable`, "the site tells localhost which PHP serves it",
check `host_php_probe`: for an EC2 site, done when the
open state has a `php_version`; for a DreamHost site, done with the detail
"DreamHost's panel says which PHP serves it", the way a site with no test
domain passes the test-domain step. It starts as an idea until the first site
proves it (mg, on deploy).

### The scanner (`tools/site-standards/scan.py`, `SiteStore::forScanner`)

`forScanner` builds each site's links from whichever kind it has and hands
`ServerChoice::pick` a kind-neutral link array (`link_id`, `where`,
`directory`, `ssh_alias`); `pick`'s rules do not change. For an EC2 site the
directory is absolute; `scan.py`'s `SERVER_DIRECTORY` rule must accept a
leading slash (still no `..`). `php_current` and the `test` block come from the
EC2 open state. `ask_server` runs the same `git rev-parse HEAD` over the EC2
alias; a server whose directory is not a git checkout is skipped with the
reason, exactly as a DreamHost server would be. `SITE_SERVER_SSH_ALIAS`,
`SITE_SERVER_DIRECTORY`, `SITE_TEST_*` are filled. The three checks that ask a
server anything (`js_soaked_on_production`, `js_deprecations_clear`,
`suite_passes_on_php`) carried the same relative-directory rule as `scan.py`
and learn the leading slash through one helper in `lib.sh`; no other check
script changes.

### The board (`Board`, `standards.tpl.php`)

Option 3 from the mockups: one board, and inside each standard's panel the
sites are grouped under small muted subheadings, **On DreamHost** then **On
EC2**, shown only when the group has a site. A site with no link of either
kind sits under a third subheading, **No host linked yet**, with the 😂 mark
and the footer saying so. No badges; the three marks and no others.

The footer line for an EC2 site says, in the same muted sentence as today:
"Its server is `<alias>` in `<directory>`; the web PHP is 8.1 (read over
loopback); its server runs `<branch>` at `<commit>`; last checked … UTC." When
the sweep could not read the PHP: "the sweep could not read which PHP serves
it". `server_branch` and `server_commit` keep coming from the scan, as today.

The shared nav keeps saying **DreamHost inventory** for this bubble, because
the other five pages behind it are DreamHost pages. The standards page intro
gains one sentence: "Sites on EC2 are here too; their servers are entered with
site.sh." Renaming the nav waits for a page that is about more than DreamHost.

## Constraints

- PHP 8.5 on mg, with the repo's gates: unit suite, phpcs, PHPStan at the
  level in `phpstan.neon`, the pre-commit hook. `deets.sh` must run on an
  Ubuntu 22.04 server with bash, curl and git and nothing else assumed.
- Migrations under `db_schemas/50_ec2_sites/` use letter prefixes
  (`a_create_ec2_servers.sql`, …) and apply through `/admin/migrate_tables.php`.
- Primary-key column names are unique across the project.
- The sweep and the scanner never write to a server. The mg server never
  opens an ssh connection to anything.
- Read-only facts only: `login_confirmed_on` is set by a successful sweep, not
  by hand.

## Left to the builder

- Exact JSON shape of the sweep document and the importer's refusal messages,
  following `SweepPayload` / `SweepFormatException`.
- How `Board::standards()` represents the groups to the template.
- Unit tests: `ServerChoice` on kind-neutral links, `HostChecks` for the EC2
  column of the table above and for `host_php_probe`, the one-kind rule in
  `SiteStore`, grouping in `Board`, and the importer's shape validation and
  open/close of states. Tests use made-up names.
- Order of commits inside the bubble (migration, store, CLI, checks, board,
  sweep, step file, probe file).

## Open until the client sites are ready

- Whether the client servers' deploy directories are git checkouts; if not,
  the scanner skips those sites with the reason until they are, and
  `deployed_at_utc` is the clue a later step can use.
- The probe file in each client site, and letting it through their bootstrap:
  the client sites' own agent does that; mg only reports what it reads.
