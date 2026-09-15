# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Talking to auto_mgTester — the wake protocol (do this; do NOT /loop)

mgClaude and auto_mgTester (aiu 36) — the autonomous Codeception tester for
this project — are one agent split across two boxes. jikan carries the message;
shared-mount log files are the doorbells. Two unconditional rules, every session:

1. **At session start, before other work**, start a persistent Monitor on your
   reply log so his replies arrive event-driven:
   - `command`: `tail -n 0 -f ~/work/rob/mg.robnugen.com/hey_mgClaude.log`
   - `persistent: true`, `timeout_ms: 3600000`,
     `description: hey_mgClaude.log — replies from auto_mgTester`
   - Each new line is `<ISO-ts> Check jikan <id>` → fetch that jikan message
     (`mcp__jikan__list_inbox`) and act on it.

2. **After every `mcp__jikan__send_inbox` to auto_mgTester (aiu 36)**, ring his
   doorbell so his side fires immediately instead of waiting on the backstop:
   ```bash
   echo "$(date -Iseconds) Check jikan <MESSAGE_ID>" >> ~/work/rob/mg.robnugen.com/hey_aamgT.log
   ```
   Use the `message_id` that `send_inbox` returned.

Do NOT poll with `/loop` or `ScheduleWakeup` — the session-start Monitor IS the
wait mechanism. The doorbell makes wakes instant; if one is ever missed (e.g. a
Vagrant suspend) the flock supervisor's ≤60 s backstop still catches it, so the
message is never lost. If an outbound to 36 stays `seen_at: null` well past a
couple minutes, his Vagrant stack is down — holler to Rob, don't keep waiting.
mgTester (aiu 10) is the *manual* tester and has no doorbell — this protocol is
auto_mgTester only.

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
        standards-codeception-runner:php8.3 php -d memory_limit=1G vendor/bin/phpcbf

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

Only the commit tier exists today. Integration/webdriver suites need a running
target and are NOT part of this gate — ask your automated test agent, auto_mgTester, to run them.

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

## Development Notes

- No runtime dependencies — pure PHP with custom autoloader. `composer.json` holds dev tools only (Codeception, phpcs, PHPStan); `vendor/` is never deployed
- `?debug=1` URL parameter enables debug output; `print_rob($var)` echoes `<pre>` dump and exits; `print_roblog($var, $label)` appends to `~/rob.log`

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
