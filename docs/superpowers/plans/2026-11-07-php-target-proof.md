# Proving code on the next PHP: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Before Rob switches a site to a new PHP, an agent step proves the code compiles on the current and target PHP, and for sites with a test domain a second step proves the site's own safe suite passes on it.

**Architecture:** mg learns one new hand-kept fact per site (its test domain) and answers one new host check from the sweep. The scanner on Lemur 13 gets two new check scripts that run only through standards-mcp runner scripts, and is handed the facts they need by `site.sh sites`. New step files sit in the existing `php_version` track ahead of Rob's switch step.

**Tech Stack:** PHP 8.5 (mg, Codeception Unit), bash and `python3 -I` (scanner, checks), Docker runner images in `~/work/rob/standards-mcp`.

**Spec:** `docs/superpowers/specs/2026-11-07-php-target-proof-design.md`

## Global Constraints

- This repo is public: step files, tests, comments and this plan use `mg` or made-up names (`example`, `example.test`); real shortcodes and domains live only in the database and issues.
- Only a check ticks a step. A check writes nothing to a repo, prints one line of at most 255 characters, and exits 0 done / 1 not yet / 2 could not tell.
- A check runs a site's code only through standards-mcp runner scripts; the runner mounts the repo read-only.
- Tests run only when necessary: a bare `scan.sh` over every site must not run a suite for every site (Rob: it would take over an hour).
- The target PHP is a step `param` such as `8.5`; no version is hard-coded in code or checks. Runner images exist today for 8.1, 8.3 and 8.5 only; a missing image is exit 2, never a pass.
- Migration SQL files under `db_schemas/NN_*/` start with a letter and an underscore (`a_...`). Migration `02+` is applied by hand at `/admin/migrate_tables.php`.
- Before calling a task done: `~/work/rob/standards-mcp/run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh` on `~/work/rob/mg.robnugen.com`, all passing (or `check_tests(repo="mg")`). The commit hook also checks every commit.
- Commits: branch `php-target-proof` (already open, BEGIN `2d439e9`); one layer per commit; never touch master; close with `git close-bubble`.

## Review Focus

- A site with a test domain that is also its only linked domain. `--test=` must refuse naming the server domain as the test domain.
- A test domain with no sweep PHP version. `dh_test_serves_php` must fail with the domain's name, not pass.
- A site with no test domain. `dh_test_serves_php` passes with a line saying so; the suite check also passes with a line, and never runs Docker.
- Production already on the target PHP (or newer). Tier 1 lints on the target image alone and does not demand an older image.
- The test domain runs a different commit from the checkout. The suite check says "could not tell", because its result would describe other code.

## Files

| File | Responsibility |
|---|---|
| `db_schemas/48_standards_test_domain/a_add_std_sites_test.sql` | the new column |
| `classes/Inventory/Standards/SiteStore.php`, `SiteCommand.php` | set, clear and guard the test domain; list it for the scanner |
| `classes/Inventory/Standards/SiteFacts.php`, `HostChecks.php`, `Board.php` | `dh_test_serves_php` |
| `classes/Inventory/Standards/TestDomainCandidates.php` | pure: which domains look like a site's test counterpart |
| `tools/site-standards/scan.py`, `checks/code_runs_on_php.sh`, `checks/suite_passes_on_php.sh` | hand facts to checks; the two checks |
| `~/work/rob/standards-mcp/run-php-lint.sh`, `run-site-suite.sh` | the only ways a check runs site code (own repo) |
| `tools/site-standards/steps/template/php_version/*.md` | three new steps; one how-to rewritten |
| `CLAUDE.md`, the design doc | the facts they state |

---

### Task 1: The test-domain fact

**Files:**
- Create: `db_schemas/48_standards_test_domain/a_add_std_sites_test.sql`
- Modify: `classes/Inventory/Standards/SiteStore.php`, `SiteCommand.php`
- Test: `Tests/Unit/StandardsSiteCommandTest.php`

**Interfaces:**
- Produces: `std_sites.test_site_domain_id INT UNSIGNED NULL` with a foreign key to `dh_site_domains(dh_site_domain_id)`, as `server_site_domain_id` is. `site-set --test=<domain>[<path>]` sets it, `--test=` clears it. `SiteStore::set()` accepts `array{test?: string}`. `forScanner()` entries gain `test: ?array{domain: string, php_version: ?string, ssh_alias: ?string, directory: ?string}`.

- [ ] **Step 1: Write the failing tests** in `StandardsSiteCommandTest`: `testSiteSetTakesTest` (`parse(['site-set','--shortcode=mg','--test=example.test'])` returns the option), and `testSiteSetStillRefusesAnUnknownOption` (`--tset=` throws).
- [ ] **Step 2: Run the Unit suite** and see the first fail.
- [ ] **Step 3: Add `'test'` to the optional list of `site-set`** and map it to `$changes['test']` in `run()`; update the class docblock's command list.
- [ ] **Step 4: Write the migration** (the `ALTER TABLE ... ADD COLUMN ... AFTER server_site_domain_id` plus foreign key, with a comment saying NULL means the site has no test domain).
- [ ] **Step 5: In `SiteStore::set()`**, handle `test` like `server`, using `linkId()`. Refuse when the named link is the site's server link ("is the server of site X; a domain cannot be both"). In `unlink()`, refuse when the link is the test domain, with the same wording pattern as the server refusal.
- [ ] **Step 6: Extend `forScanner()`** to return the `test` entry, reusing the same `dh_domain_states` / `dh_users` join and `ServerChoice`-style directory building for the test link. Hand-verify against the real DB after Task 9's migration (database code is verified by hand in this repo).
- [ ] **Step 7: Run the gates; commit** as `Record a site's test domain` (migration + store), then `Take --test= on site-set`.

### Task 2: `dh_test_serves_php`

**Files:**
- Modify: `SiteFacts.php`, `HostChecks.php`, `Board.php:~163`
- Test: `Tests/Unit/StandardsHostChecksTest.php`

**Interfaces:**
- Consumes: Task 1's column.
- Produces: `SiteFacts` gains `?array $testDomain` (`array{domain: string, php_version: ?string}`) as a trailing constructor argument defaulting to `null`. `HostChecks::NAMES` gains `'dh_test_serves_php'`. `HostChecks::answer('dh_test_serves_php', '8.5', $facts)`.

- [ ] **Step 1: Write failing tests** `testNoTestDomainPassesWithAReason` (Pass, detail contains "no test domain"), `testTestDomainOnTheTargetPasses`, `testTestDomainBelowTheTargetFails` (detail names the domain and its version), `testTestDomainWithNoVersionFails`, `testBadParamIsUnknown` (`'8'` → Unknown, as `dh_serves_php` does).
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement `testServesPhp(SiteFacts, string): CheckResult`**, sharing the version-comparison with `servesPhp` rather than copying it.
- [ ] **Step 4: Load the test domain in `Board`**: add `std_sites.test_site_domain_id` to the site query and one extra query for that link's domain and `php_version`.
- [ ] **Step 5: Gates; commit** as `Answer whether a site's test domain serves the PHP`.

### Task 3: Finding which domains are test domains

**Files:**
- Create: `classes/Inventory/Standards/TestDomainCandidates.php`
- Modify: `SiteCommand.php` (new command `test-candidates`)
- Test: `Tests/Unit/StandardsTestDomainCandidatesTest.php`

**Interfaces:**
- Produces: `TestDomainCandidates::find(array $site, list<array> $domains): list<array{domain: string, reasons: list<string>}>` where `$site` is `array{server_domain: string, dh_user: ?string, checkout_path: ?string}` and each `$domains` entry is `array{domain: string, dh_user: ?string, web_directory: ?string, linked_to: ?string}` (`linked_to` is the shortcode of the site the domain is linked to, or null). `site.sh test-candidates` prints, per site that has a named server and no test domain yet, the candidates with their reasons, as JSON.

Evidence to use, all read from the sweep and the site table, never from guessing a naming scheme: same DreamHost user as the server domain; same registrable parent domain; a name equal to the production name plus or minus one trailing letter (`example` and `examplet`); a `web_directory` whose last path segment matches the server domain's; not linked to a different site. A single piece of evidence is not enough to list a candidate; at least two are. Each candidate carries its reasons so Rob can judge it.

- [ ] **Step 1: Write failing tests**: `testSameUserAndNearTwinNameIsACandidate`, `testSameUserAloneIsNotEnough`, `testADomainLinkedToAnotherSiteIsLeftOut`, `testReasonsArePrintedWithEachCandidate`, `testNoCandidatesYieldsAnEmptyList`.
- [ ] **Step 2: Implement `find()`** as a pure function; wire `test-candidates` in `SiteCommand` and `SiteStore` (one query for the sites without a test domain and one for the sweep's domains).
- [ ] **Step 3: Gates; commit** as `List the domains that look like a site's test twin`.

### Task 4: Hand the scanner what the checks need

**Files:**
- Modify: `tools/site-standards/scan.py` (`scan_site`), `SiteStore::forScanner()`
- Test: by hand with `scan.sh --dry-run` and `SCAN_SITE_CMD` stubbed to a canned JSON file (the scanner's existing overrides)

**Interfaces:**
- Consumes: Task 1's `test` entry and a new `php_current: ?string` per site (the sweep's PHP version of the site's server domain).
- Produces: checks run with extra environment variables `SITE_PHP_CURRENT` (empty when unknown), `SITE_TEST_DOMAIN` (empty when none), `SITE_TEST_PHP`, `SITE_TEST_SSH_ALIAS`, `SITE_TEST_DIRECTORY`, and `SITE_SHORTCODE`. No other change to how a check is called (`check REPO PARAM`).

- [ ] **Step 1: Add `php_current` to `forScanner()`** from the server domain's state row.
- [ ] **Step 2: In `scan_site`, build a per-site `env` copy** with the variables above, empty strings rather than missing keys, and pass it to `subprocess.run`.
- [ ] **Step 3: Verify by hand**: a stub `SITE_*` echo check prints the values for a site with and without a test domain.
- [ ] **Step 4: Commit** as `Tell checks the site's PHP and test domain`.

### Task 5: Runner scripts in standards-mcp

Separate repo (`~/work/rob/standards-mcp`); its own branch and its own gates.

**Files:**
- Create: `run-php-lint.sh`, `run-site-suite.sh`

**Interfaces:**
- Produces: `run-php-lint.sh REPO VERSION` runs `php -l` on every `*.php` under REPO outside `vendor/`, `Tests/_output` and `*/_generated`, on `standards-codeception-runner:php<VERSION>`, repo mounted read-only, `--network none`. Exit 0 all compile; 1 prints `FILE:LINE message` of the first that does not; 2 `GATE SKIP` when Docker or the image is missing. `run-site-suite.sh REPO VERSION` runs the repo's root-level `safe-suite.sh` (Task 7) on that image with the repo read-only and network allowed, passing `SITE_TEST_DOMAIN` through; exit codes as above; refuses (exit 2) when `safe-suite.sh` is missing.

- [ ] **Step 1: Write `run-php-lint.sh`** on `run-unit.sh`'s pattern (absolute-path normalise, `GATE SKIP` convention, read-only mount).
- [ ] **Step 2: Verify**: a clean tree passes; a tree with a deliberate `<?php 1 +;` file exits 1 naming that file; `VERSION=9.9` exits 2.
- [ ] **Step 3: Write `run-site-suite.sh`**, same pattern, `--network` default; verify missing `safe-suite.sh` exits 2 and a stub script's 0 and 1 map through.
- [ ] **Step 4: Commit** each script separately (`Lint a repo on a chosen PHP`, `Run a repo's safe suite on a chosen PHP`).

### Task 6: Tier 1 check `code_runs_on_php`

**Files:**
- Create: `tools/site-standards/checks/code_runs_on_php.sh`

**Interfaces:**
- Consumes: Task 4's `SITE_PHP_CURRENT`; Task 5's `run-php-lint.sh`; `lib.sh` (`need_repo`, `pass`, `not_yet`, `unknown`).
- Produces: `code_runs_on_php.sh REPO X`.

- [ ] **Step 1: Write the check.** `X` must match `^[0-9]+\.[0-9]+$` else exit 2. `SITE_PHP_CURRENT` empty → exit 2 `its current PHP is not in the sweep`. Lint on `X`; if the current version is lower than `X` (use `sort -V`), also lint on the current. Both pass → exit 0 `compiles on <current> and <X>`; the first failing file → exit 1 naming the version and file; a runner exit 2 → exit 2 with its line.
- [ ] **Step 2: Verify by hand** on `~/work/rob/mg.robnugen.com` with `SITE_PHP_CURRENT=8.1` and `X=8.5` (mg is 8.5-only syntax, so the 8.1 lint **must** fail naming a file: that proves the check really lints the older version), then with `SITE_PHP_CURRENT=8.5` (passes, one image), then `SITE_PHP_CURRENT=` (exit 2).
- [ ] **Step 3: Commit** as `Check that code compiles on the current and target PHP`.

### Task 7: Tier 2 check `suite_passes_on_php`

**Files:**
- Create: `tools/site-standards/checks/suite_passes_on_php.sh`

**Interfaces:**
- Consumes: Task 4's variables; Task 5's `run-site-suite.sh`; the repo's root-level `safe-suite.sh` (the convention chosen here: a script that runs only suites safe to run against the test domain, exits 0 or 1, and receives `SITE_TEST_DOMAIN`); `code_runs_on_php.sh`.
- Produces: `suite_passes_on_php.sh REPO X`; remembered results in `${XDG_STATE_HOME:-$HOME/.local/state}/site-standards/suite-<SITE_SHORTCODE>` holding `<commit> <outcome> <line>`.

- [ ] **Step 1: Write the check**, in this order, each failing case exiting at once: no `SITE_TEST_DOMAIN` → exit 0 `no test domain; nothing to run`. Test PHP below `X` → exit 1 `the test domain is not on <X> yet`. `code_runs_on_php.sh` not exit 0 → exit 2 `code does not compile on both PHPs yet`. `safe-suite.sh` missing → exit 2 `the repo has no safe-suite.sh`. The test domain's commit (asked over ssh with `SITE_TEST_SSH_ALIAS` and `SITE_TEST_DIRECTORY`, as `scan.py` asks a server) differing from the repo's `HEAD` → exit 2 `the test domain runs <sha7>, the checkout is <sha7>`. A remembered result for this `HEAD` → print it and exit with its code. Otherwise run `run-site-suite.sh`, remember the result for this `HEAD`, exit 0 or 1.
- [ ] **Step 2: Verify by hand with a stub repo** for each early exit above, then one real run against a throwaway repo whose `safe-suite.sh` exits 0, then 1, then run again at the same commit and confirm the Docker runner is **not** called a second time (put a marker file in the stub runner via `STANDARDS_MCP`).
- [ ] **Step 3: Verify the scan stays fast**: `scan.sh --dry-run` over all sites with `STEPS_REF` on this branch shows the suite check as `pass (no test domain...)` or an early exit for every site, and no Docker started for them.
- [ ] **Step 4: Commit** as `Check that a site's safe suite passes on the target PHP`.

### Task 8: Step files, how-to, docs

**Files:**
- Create: `steps/template/php_version/code_runs_on_php_8_5.md` (position 2), `test_domain_serves_php_8_5.md` (position 4), `suite_passes_on_php_8_5.md` (position 6)
- Modify: `steps/template/php_version/serves_php_8_5.md` (how-to only), `CLAUDE.md` ("Site standards"), `docs/superpowers/specs/2026-11-07-php-target-proof-design.md`

**Interfaces:**
- Consumes: checks `code_runs_on_php` (agent, param `8.5`), `dh_test_serves_php` (rob, `8.5`), `suite_passes_on_php` (agent, `8.5`), all `status: idea` until one site has done them.

- [ ] **Step 1: Write the three step files.** Explanations: two to five sentences each, naming no site. The first's how-to: run `run-rector.sh` on the target image with the target's removal/deprecation rules only and apply one rule per commit; run PHPStan with `phpVersion` set to the target; lint on both versions before deploying; **never** the "modernise to the new syntax" sets, which come after the switch. The second: Rob switches only the test domain in the panel. The third: the agent runs the repo's `safe-suite.sh` through the Docker harness; declare in `safe-suite.sh` only tests that cannot create real records on a shared backend; deploy and let the scan confirm.
- [ ] **Step 2: Rewrite `serves_php_8_5`'s how-to** so item 1 points at the three steps above instead of "make sure the code is ready"; leave its title, position, check and slug alone, so issues and NAh marks keep working.
- [ ] **Step 3: Update `CLAUDE.md`** with one bullet per new fact: `--test=`, `dh_test_serves_php`, the two checks, and `safe-suite.sh`.
- [ ] **Step 4: Fix the design doc** to say the new steps join the `php_version` track at positions 2, 4 and 6 rather than a new track per target, with the slug carrying the version; and replace its "Calls made" item on `scan.sh` environment variables with the actual names from Task 4.
- [ ] **Step 5: Run `StepTree` on the new files** (the existing Unit tests, plus `tools/import_site_standards.php --dry-run` through `scan.sh --dry-run`) to prove they parse. Commit the step files, then the docs, as separate commits.

### Task 9: Rollout, with Rob (no code)

- [ ] **Step 1: Rob applies migration 48** at `/admin/migrate_tables.php` after deploying the branch.
- [ ] **Step 2: Find the test domains.** Run `site.sh test-candidates`. For each site with one candidate that has two or more reasons, show Rob the pairing and its reasons and ask him to confirm. For a site with several candidates, or none but a name that looks like a test twin, ask him which domain it is. Where the test domain is not yet linked to the site, run `site.sh site-link` first. Then `site.sh site-set --shortcode=<code> --test=<domain>`.
- [ ] **Step 3: Check the result** with `site.sh sites`: every site Rob named has a `test` entry, and `server` is still unchanged.
- [ ] **Step 4: `scan.sh --dry-run`**, then Rob deploys the step files, then `scan.sh <shortcode>` for one site with a test domain and one without, and read the page: the no-test-domain site shows its two test steps as ticked with a reason, the other shows them open.

---

## Self-review

- Spec coverage: the test-domain fact (1), host check (2), finding the pairs (3, 9), two-version handoff (4), Tier 1 (5, 6), Tier 2 with the necessary-only rule (5, 7), step files, how-to and docs (8). The design's "Open for the builder" items are settled: file name `safe-suite.sh`, environment variables (4), step placement (8).
- Plan call that departs from the design: the new steps join the existing `php_version` track instead of a new track per target (the design's two statements disagreed). Task 8 corrects the design.
- Plan addition not in the design: the suite check requires the test domain to run the same commit as the checkout, since otherwise the result describes other code.
