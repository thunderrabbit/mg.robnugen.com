# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Git Workflow

**Claude cannot commit on master.** Create a feature branch first; Rob integrates via merge bubble.

## Standards gates (unit tests + phpcs + PHPStan) — run before you report a change as done

mg carries three commit-tier gates from `~/work/rob/standards-mcp` (hermetic:
read-only mount, no network, no credentials):

    ~/work/rob/standards-mcp/run-unit.sh    ~/work/rob/mg.robnugen.com   # Codeception Unit suite
    ~/work/rob/standards-mcp/run-phpcs.sh   ~/work/rob/mg.robnugen.com   # PSR-12 style gate
    ~/work/rob/standards-mcp/run-phpstan.sh ~/work/rob/mg.robnugen.com   # PHPStan, level in phpstan.neon

All three must pass. Setup and fixing:

- Populate `vendor/` once per clone with the stock composer image (Lemur has no
  composer; rootless docker, so container root writes as your user):

      docker run --rm -v "$PWD":/app -w /app composer:2 \
        composer install --ignore-platform-reqs --no-interaction

- Auto-fix style with phpcbf, then fix what's left by hand:

      docker run --rm --network none -v "$PWD":/app -w /app \
        standards-codeception-runner:php8.5 php -d memory_limit=1G vendor/bin/phpcbf

- `phpcs.xml` is PSR-12 over `classes/`, `wwwroot/`, `prepend.php` and `Tests/`.
  Its PSR-12 excludes are a manual-cleanup backlog; the `_before`/`_after`
  exclusion under `Tests/` is deliberate (Codeception's names).
  `templates/*.tpl.php` are out of scope.
- **Every commit is checked.** `.githooks/pre-commit` runs phpcs on the staged
  content of each staged PHP file in `phpcs.xml`'s scope, and PHPStan over the
  whole staged tree whenever PHP, `phpstan.neon` or `composer.lock` is staged.
  Any error or warning blocks the commit. Enable it once per clone with
  `git config core.hooksPath .githooks`. Merges (`git close-bubble`) don't run
  it; `git commit --no-verify` is the emergency bypass, not a way to skip a fix.
  The hook doesn't run the unit suite; run that yourself.
- PHPStan climbs one level per bubble toward level 10 (mg #505), with no
  baseline. Fix a finding rather than baselining it or lowering the level.
  Entry scripts name the globals they take from `prepend.php` in a `@var` block
  under the include; `api/v1/_*.php` handlers do the same for `index.php`'s
  variables. `classes/Parsedown.php` is vendored and excluded from analysis.
  The one ignore in `phpstan.neon` covers Codeception actor method calls under
  `Tests/`, whose traits are generated and gitignored. A finding PHPStan can't
  see past gets `// @phpstan-ignore <identifier> (reason)` on that one line,
  never a blanket ignore: the `catch` around `new \Config()` is live because
  the autoloader throws.

- **Rector** keeps the code at PHP 8.5 (`rector.php`: `LevelSetList::UP_TO_PHP_85`
  over the phpcs scope; `classes/Journal` and `tools/journal-sync` are skipped
  because they also run on Lemur 13's PHP 8.1). It runs only in Docker:

      ~/work/rob/standards-mcp/run-rector.sh ~/work/rob/mg.robnugen.com                      # dry run; exit 2 = changes found
      ~/work/rob/standards-mcp/run-rector.sh ~/work/rob/mg.robnugen.com --apply --only=<FQCN>  # one rule

  Apply one rule per commit (`Rector <RuleName>: <what changed>`), tidy with
  phpcbf, and run the Unit suite between rules. Rector takes its target from
  the PHP it runs on, so it must run on the php8.5 image. rector/rector is held
  at 2.6 until PHPStan moves past 2.3.4, which 2.8 requires.

The `standards` MCP server runs the Unit suite deterministically too. Before
reporting any code change as done, call:

    check_tests(repo="mg")

It runs the Unit suite in a keyless, networkless container (no creds, no
network, source read-only) and returns {status, pass, checks[], ...}. Act on
status:
- pass    → hermetic Unit suite green; safe to claim.
- fail    → tests failed; fix first (see checks[].violations).
- error   → a "unit" test needs creds or the network, so it aborted — it's
            misfiled. Move it to the Integration suite (see ADD_CODECEPTION.md);
            never add credentials to make it pass.
- skipped → no unit suite found.

Only the commit tier exists today. The Webdriver, PhpBrowser, Api and
Integration suites point at production (https://mg.robnugen.com), so they test
what's deployed and are NOT part of this gate. Webdriver runs in Docker via
`~/work/rob/standards-mcp/run-webdriver.sh`.

## Project Overview

This is a minimalist PHP web application framework designed for DreamHost deployment. It's a custom template-based site with admin dashboard functionality, database migration system, and user authentication using cookies stored in the database.

## Key Architecture

### Core Components

- **Template System**: `classes/Template.php` - Custom templating engine with layout nesting via `grabTheGoods()` method
- **Database Layer**: `classes/Database/` - PDO-based database abstraction with migration system
- **Authentication**: `classes/Auth/` - Cookie-based login system with browser matching
- **Configuration**: Must create `classes/Config.php` from `classes/ConfigSample.php` with actual database credentials
- **Bootstrap**: `prepend.php` - Application initialization, autoloader, and database checks

### Database Migration System

- Migrations stored in `db_schemas/` with numbered prefixes (`00_`, `01_`, etc.)
- Prefixes `00` and `01` applied automatically on boot; `02`+ applied manually via `/admin/migrate_tables.php`
- Schema files must follow `create_*.sql` naming convention (use letter prefixes for ordering within a schema dir, not digits)
- Applied migrations tracked in `applied_DB_versions` table
- Manual rollbacks only (no automated rollback — use PHPMyAdmin)

## Development Workflow

### Deployment

- `scp_files_to_dh.sh` is gitignored and must be created locally — watches for changes, syncs to DreamHost via SCP
- Target: `barefoot_rob@drc:/home/username/domain.com/`
- `wwwroot/index.php` has a hardcoded DreamHost path — not portable; don't generalize it

### Initial Setup

1. Copy `classes/ConfigSample.php` to `classes/Config.php` and configure database credentials
2. First visit to site triggers automatic schema creation and admin user setup
3. Database must exist before application runs (checked by `DBExistaroo`)

### Authentication Flow

- Session-based with database-stored cookies
- First-time setup redirects to admin user creation unless visiting `/login/register.php`
- Login state managed by `Auth\IsLoggedIn` class

### Connected apps (limited actors)

An actor with `agent_inbox_user.todos_limited = 1` is a "limited actor": a third-party app (BG Bells) that may touch only the todos listed for it in `aiu_todo_grants` (`db_schemas/41_aiu_todo_grants/`, read through `Auth\TodoGrants`).

- `Auth\Guards::isLimited()` spots them; `limitedRoute()` lets them reach only `GET /todos/granted`, `POST /todos/complete-with-session`, `POST /sessions`, `POST /sessions/backfill` and `PATCH /sessions/{id}/stop`.
- `Guards::todo()` refuses an ungranted todo with `Todo not found or access denied` (403), the same as a todo they don't own; `Guards::activity()` refuses an ungranted activity with `This app may not log that activity` (403).
- `wwwroot/api/v1/index.php` takes `Authorization: Bearer` (resolved by `Auth\BearerToken`) only from limited actors; every other actor gets 401 `Use X-API-Key`. `wwwroot/mcp/index.php` refuses limited actors outright.
- `/mcp/token.php` gives limited actors a 31-day sliding refresh token instead of 400 days (`Auth\TokenLifetime`). A password change revokes all connected apps via `ConnectedApps::revokeAll`.
- The `bgbells` client (`Auth\OAuthClients`, redirect `bgbells://oauth/callback`) is pinned on `/authorize` to the account's actor named `BG Bells` (a hand-made actor; per-user actors are mg #801) and always shows Allow/Deny, never a silent reissue.
- v1 `/todos/complete-with-session` applies the daily cap (logical day starts 03:00) to every caller; the web UI copy is unchanged.

### Journal editing (Lemur 13 sync)

`/journal/` lets Rob edit recent journal entries from his phone; Lemur 13 syncs them. Design: `docs/superpowers/specs/2026-11-05-journal-editing-design.md`.

- Lemur 13 runs a **pinned copy** at `~/work/carrie/journal-sync/`, called at the end of `~/work/carrie/run.sh`. Changing `classes/Journal/*` or `tools/journal-sync/journal_sync.php` does nothing on Lemur 13 until `tools/journal-sync/install.sh` is re-run.
- Its key (`~/.config/journal-sync/mg.key`, 0600) belongs to actor `Journal Sync` with `agent_inbox_user.journal_sync = 1`. `Guards::journalRoute()` keeps that key to `/journal-sync` and every other key out of it.
- `Journal\EntryCheck` is the one save check, run by mg on Save and by Lemur 13 before writing. Those classes also run under Lemur 13's PHP 8.1 (no curl extension), so keep them free of 8.2+ syntax.
- On the server, plain `php` is 8.2; use `/usr/local/php85/bin/php` for CLI tools.

### Site standards (which sites are behind)

`/admin/dh/standards.php` shows, for each site entered, which written-up steps it has done. Design: `docs/superpowers/specs/2026-11-06-dh-standards-design.md`.

- **A step is a Markdown file** under `tools/site-standards/steps/<standard>/<track>/<step>.md`, with `_standard.md` and `_track.md` naming the directories. `Inventory\Standards\StepTree` is the only reader and refuses anything malformed. A step is an `idea` until one site has done it, then `proven`; only proven steps count against a site.
- **Only the step files deployed on mg count.** Rob approves by deploying, and not only `master` gets deployed. The scanner asks the server which commit it runs and reads the step files from that commit with git, never from the working tree; its own check scripts must match that commit or it stops.
- **A step is ticked by a check, never by hand.** `Inventory\Standards\HostChecks` answers the ones mg knows from its own tables (`dh_has_domain`, `dh_ssh_login`, `dh_serves_php`, `dh_test_serves_php`, `repo_located`). Every other check is `tools/site-standards/checks/<name>.sh REPO [PARAM]`: one line on stdout, exit 0 done, 1 not yet, 2 could not tell, and it never writes to the repo.
- **The scanner runs on Lemur 13** (the checkouts and Docker are there): `tools/site-standards/scan.sh [--dry-run] [shortcode ...]`. It asks each site's server over ssh which commit it runs, and checks the site only when the local checkout is clean at exactly that commit; any other site is skipped, with the reason, and keeps its results. Results go to `tools/import_site_standards.php` over `ssh mg`, like a sweep. Nothing schedules it yet.
- **A check can be remembered per commit.** A check whose first 20 lines hold `# memo: commit` is not rerun while the site's commit, the check script, `lib.sh`, the helper files in `checks/`, the step's param, the `SITE_*` facts and the standards-mcp tools (runner scripts, local `standards-*` docker images) are all unchanged (`tools/site-standards/memo.py`). Only pass and fail are kept, never "could not tell". The memo is one JSON file per site in `~/.local/state/site-standards/`, outside every repo, and a remembered line says `(remembered from <time>)` on stderr only; what mg receives is the same. `scan.sh --fresh` reruns everything. Without the header a check runs every scan, so leave it off anything that asks a server or the network, reads the clock or git history beyond the commit, runs another check, or reads the checkout's `.git/config`; `tests/test_memo_headers.py` names those and scans for them. Python tests: `python3 -I -m unittest discover -s tools/site-standards/tests`. A scan of `mg` took about 24 s cold, 4 s remembered.
- **Deployed means the server's commit, not a branch name** (#804). Servers keep old branch names, timestamped prod branches and server-only config branches, so the server's branch is reported on the page but never compared. The server is the user's `ssh_alias` (`dh_users`) in the domain's `web_directory` (`dh_domain_states`, relative to home) plus any `path_prefix`, chosen by `Inventory\Standards\ServerChoice`. A site whose domains have different checkouts (a test domain beside production) names production once with `site.sh site-set --server=<domain>[<path>]`; there is no `--deployed=` any more. A site preparing a long upgrade on its own branch names it with `--upgrade=<branch>`; the scanner then checks the checkout at that branch's tip, the page says so beside what the server runs, and the importer refuses an off-server scan on any other branch.
- **Site facts are entered by hand** with `tools/site-standards/site.sh` (`site-add`, `site-set`, `site-link`, `site-unlink`, `sites`, `test-candidates`). The pages are read-only except for one thing: the board's order. Rob drags sites by their handle to reorder them, into Pinned (above the host headings, whatever the host) or back to their own host's block (`std_sites.pinned`, `sort_order`, `db_schemas/53_standards_site_order/`; saved by `wwwroot/admin/dh/standards_order.php`, rules in `Inventory\Standards\SiteOrder`). The order is how much he cares, not how far behind a site is, so nothing computes it.
- **A site's notes on a step are an mg issue** in the site's own project, titled `<step>.<shortcode>` (`Inventory\Standards\Names`). An issue in status **NAh** ("Not Applicable here") makes that step count as done for that site; an issue marked Done does not.
- **A site's test domain is a hand-kept fact**: `site.sh site-set --shortcode=<code> --test=<domain>` (clear with `--test=`), never the same link as `--server=`. `site.sh test-candidates` lists the domains that look like a site's test twin, with reasons, for Rob to confirm. A site with no test domain skips the steps that need one; they pass with a line saying so.
- **Each site has its own target PHP**: its `php_target` in standards-mcp's `repos.yaml`, the same registry the runner scripts pick their image from (8.5 for most; EC2 sites and a few others are 8.3). A step with `param: target` and `{target}` in its title is measured against it: `scan.py` reads the registry, replaces the param, sets `SITE_PHP_TARGET`, and sends `php_target` with the site (`std_scan_sites.php_target`, `db_schemas/54_standards_php_target/`), so mg's own serves checks and the titles use the same version (`Inventory\Standards\PhpTarget`). A site with no target there gets "could not tell". Rob raises a site's target in `repos.yaml` once it serves the old one, and the target steps open again for the next version.
- **Before Rob switches a site to a new PHP**, three steps in the `php_version` track run ahead of his switch: the code compiles on the current and target PHP (`code_runs_on_php`, lint through `standards-mcp/run-php-lint.sh`, every site), the test domain serves the target (`dh_test_serves_php`, Rob's panel change), and the site's safe suite passes on it (`suite_passes_on_php`). The scanner hands checks `SITE_SHORTCODE`, `SITE_PHP_CURRENT` and the `SITE_TEST_*` facts as environment variables.
- **`safe-suite.sh` is the repo's own word on what is safe to test.** `suite_passes_on_php` runs only that script, through `standards-mcp/run-site-suite.sh` (read-only repo, network on), and only when the test domain serves the target PHP, the code compiles on both, the test domain runs the checkout's commit, and no result is remembered for that commit (`~/.local/state/site-standards/suite-<shortcode>`). A site whose test backend is its live backend keeps anything that creates real records out of it.
- **Three marks and no others**: checkmark, info circle, and a crying-laughing face for a site nobody can reach (no domain, or no proven ssh login). Never add warning styling; every site here is working.
- **This repo is public.** Shortcodes, site names and anything about one site's progress live only in the database and in issues. Step files, tests and comments use `mg` or made-up names.
- `.gitignore` ignores `php*`; `tools/site-standards/**` is exempt so a track, step or check can be named `phpstan`. An ignored file leaves `git status` clean, so after adding one count what `git ls-files tools/site-standards` sees.
- Tables: `std_*` are host-neutral; `dh_site_domains` is the DreamHost link (`db_schemas/45_site_standards/`, server link and scan-time server facts in `47_standards_server/`, test domain in `48_standards_test_domain/`, the branch a site is preparing in `49_standards_upgrade_branch/`) and `ec2_site_domains` the EC2 one, with `ec2_servers`, `ec2_domains`, `ec2_sweeps` and `ec2_domain_states` (`50_ec2_sites/`). A site's links are all of one kind.
- **Sites on EC2** (design: `docs/superpowers/specs/2026-11-08-ec2-sites-on-board-design.md`) are entered with `site.sh ec2-server-add` / `ec2-domain-add`, linked with `site-link`, swept with `tools/ec2-sweep/sweep.sh` (`deets.sh` installed on the server by `install.sh`, read-only, no AWS access), and shown on the same board under their own heading. The web PHP is read on the box from the enabled Apache `php*.load` module and its dpkg package, never `php -v` and never a probe file in the site; git on the server runs with `-c safe.directory='*'` because a production directory may belong to the web user. The first successful sweep proves a server's ssh login, once. Real hostnames and aliases live only in the database.

## Development Notes

- No runtime dependencies — pure PHP with custom autoloader. `composer.json` holds dev tools (Codeception, phpcs, PHPStan) plus the platform requirement (PHP 8.5 and the extensions in `Startup\RequiredExtensions`; `prepend.php` fails by name if one is missing, so keep the two lists in step); `vendor/` is never deployed
- `?debug=1` URL parameter enables debug output; `\PrintRob\Debug::printRob($var)` echoes `<pre>` dump and exits; `print_roblog($var, $label)` appends to `~/rob.log`

### Including prepend.php

All PHP files (except templates and prepend.php itself) must include prepend.php. Use this consistent pattern regardless of directory depth:

```php
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */
```

List only the globals the page uses (`$config`, `$is_logged_in`, `$mla_request`, `$mla_database`, `$dbExistaroo`) in the `@var` block, so PHPStan knows their types. A global the page uses but doesn't list is reported as undefined, and the commit hook blocks it.

This leverages DreamHost's consistent `/home/username/domain.com/` path structure to dynamically find the project root.
