# EC2 Sites on the Standards Board Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sites served from EC2 appear on `/admin/dh/standards.php` grouped by host, measured by the same steps, with their host facts gathered over ssh and the web PHP version read through the web stack itself.

**Architecture:** A second link kind (`ec2_site_domains` → `ec2_domains` → `ec2_servers`) sits beside `dh_site_domains`; `SiteStore`, `ServerChoice` and `Board` read whichever kind a site has and hand the same shapes onward, so `HostChecks`, `SiteReport`, the scanner and every check script see no difference. An ssh-driven sweep (`tools/ec2-sweep/`) fills `ec2_domain_states` the way the DreamHost sweep fills `dh_domain_states`, using the span classes already in `Inventory\`.

**Tech Stack:** PHP 8.5 (mg), MySQL, bash + curl + git on the servers, Python 3 (`scan.py`), Codeception Unit suite, phpcs (PSR-12), PHPStan.

**Spec:** `docs/superpowers/specs/2026-11-08-ec2-sites-on-board-design.md`

## Global Constraints

- PHP 8.5; every commit passes `.githooks/pre-commit` (phpcs + PHPStan over the staged tree); the Unit suite is run by hand and through `check_tests(repo="mg")` before any task is called done.
- Migrations live in `db_schemas/50_ec2_sites/`, files named `[a-z]_*.sql`, applied through `/admin/migrate_tables.php`. Primary-key column names are unique across the project.
- Table names use `ec2_`; nothing in them is AWS-specific.
- Only three marks on the page: `✓`, `ⓘ`, `😂`. No warning styling, no badges.
- No real hostnames, ssh aliases or shortcodes anywhere in the repo: tests, step files, comments and docs use `example.test`, `t.example.test`, alias `example-ec2`, shortcode `ex`.
- The sweep and the scanner never write to a server. The mg server never opens an ssh connection.
- `login_confirmed_on` on `ec2_servers` is written once, by the importer, on the first successful sweep, and never refreshed.
- A null web PHP version is `Outcome::Unknown` with the detail `the sweep could not read which PHP serves <hostname>`, never a pass.
- Commit messages: imperative subject ≤ 50 chars, body says why, multiple `-m` flags, no heredoc, ending with `-m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"`.
- Entry scripts under `tools/` follow `tools/import_dh_sweep.php`: `declare(strict_types=1)`, `PHP_SAPI` guard, the `Mlaphp\Autoloader`, `--dry-run`, `REFUSED:` on stderr with exit 1.

## Review Focus

- A hostname linked to a site whose server has never been swept: the site shows `😂 can't reach it yet`, the ssh step says `alias found, login not proven yet: <hostname>`. Test in Task 3.
- A probe body that is not a bare version (an HTML page because the site's bootstrap redirected): the sweep records a null version, and the importer refuses any `php_version` that is not `\d+.\d+(.\d+)?`. Tests in Task 8.
- A sweep document naming a hostname mg does not know, or whose directory differs from mg's: refused, nothing imported. Tests in Task 8.
- An absolute directory with `..` in it, or linking an EC2 hostname to a site that already has a DreamHost link: refused with a sentence. Tests in Task 4.
- Two sweeps in a row that cannot read the PHP: one open state, not two. Test in Task 8.

---

### Task 1: Migration `50_ec2_sites`

**Files:**
- Create: `db_schemas/50_ec2_sites/a_create_ec2_servers.sql`
- Create: `db_schemas/50_ec2_sites/b_create_ec2_domains.sql`
- Create: `db_schemas/50_ec2_sites/c_create_ec2_sweeps.sql`
- Create: `db_schemas/50_ec2_sites/d_create_ec2_domain_states.sql`
- Create: `db_schemas/50_ec2_sites/e_create_ec2_site_domains.sql`
- Create: `db_schemas/50_ec2_sites/f_add_std_sites_ec2_links.sql`

**Interfaces:**
- Produces the tables every later task queries. Columns, exactly:
  - `ec2_servers(ec2_server_id PK, ssh_alias VARCHAR(64) NOT NULL UNIQUE, label VARCHAR(100) NOT NULL DEFAULT '', login_confirmed_on DATE NULL)`
  - `ec2_domains(ec2_domain_id PK, ec2_server_id FK, domain VARCHAR(255) NOT NULL UNIQUE, directory VARCHAR(255) NOT NULL)`
  - `ec2_sweeps(ec2_sweep_id PK, ec2_server_id FK, swept_at_utc DATETIME NOT NULL, web_server VARCHAR(100) NULL, cli_php VARCHAR(16) NULL)`
  - `ec2_domain_states(ec2_domain_state_id PK, ec2_domain_id FK, from_sweep_id FK ec2_sweeps, until_sweep_id FK ec2_sweeps NULL, php_version VARCHAR(16) NULL, probe_route ENUM('https','http_forwarded') NULL, directory_exists TINYINT(1) NOT NULL, is_git_checkout TINYINT(1) NOT NULL, head_commit CHAR(40) NULL, head_branch VARCHAR(100) NULL, deployed_at_utc DATETIME NULL, UNIQUE (ec2_domain_id, from_sweep_id), INDEX idx_open (until_sweep_id))`
  - `ec2_site_domains(ec2_site_domain_id PK, std_site_id FK, ec2_domain_id FK, path_prefix VARCHAR(255) NOT NULL DEFAULT '', UNIQUE (ec2_domain_id, path_prefix))`
  - `std_sites` + `server_ec2_site_domain_id INT UNSIGNED NULL` and `test_ec2_site_domain_id INT UNSIGNED NULL` after `upgrade_branch`, FKs `fk_std_sites_server_ec2`, `fk_std_sites_test_ec2` → `ec2_site_domains(ec2_site_domain_id)`.

- [ ] **Step 1: Write the six files**, each opening with a comment in the voice of `db_schemas/45_site_standards/b_create_std_sites.sql` saying what the table is and what writes it (hand for servers/domains/links, the importer for sweeps/states). `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin` on every table.

- [ ] **Step 2: Check the filenames and SQL parse**

Run: `ls db_schemas/50_ec2_sites/ | grep -vE '^[a-z]+_[a-z0-9_]+\.sql$'; echo "names checked"`
Expected: only `names checked` (every name starts with a letter prefix). Read each file once against the Interfaces list above: every column, key and FK named there is present and no other. Do not apply the migration from the CLI; Rob applies it at `/admin/migrate_tables.php` after deploy.

- [ ] **Step 3: Commit**

```bash
git add db_schemas/50_ec2_sites
git commit -m "Add the tables for sites served from EC2" -m "A server reached by an ssh alias, the hostnames it serves and the directory behind each, a sweep per visit, open/closed facts per hostname, and the link from a site to a hostname beside the DreamHost one." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: `ServerChoice` reads a kind-neutral link

**Files:**
- Modify: `classes/Inventory/Standards/ServerChoice.php`
- Modify: `classes/Inventory/Standards/SiteStore.php:187-205, 215-241` (the DH caller maps its rows into the new shape)
- Test: `Tests/Unit/StandardsServerChoiceTest.php`

**Interfaces:**
- Produces the link shape every later task uses:
  `array{link_id: int, where: string, path_prefix: string, directory: ?string, ssh_alias: ?string}`
  where `where` is the hostname, `directory` is the DreamHost `web_directory` (relative to home) or the EC2 `directory` (absolute), and `ServerChoice::directoryOf` is `directory . path_prefix`.
- `ServerChoice::pick(array $links, ?int $named): self` keeps its name, rules and messages, except `'no domain is linked to it'` becomes `'no domain or hostname is linked to it'` and `"the latest sweep has no directory for {$where}"` becomes `"no directory is known for {$where}"`.

- [ ] **Step 1: Update the test helper and add one test**

In `StandardsServerChoiceTest`, change `link()` to build the new keys (`link_id`, `where`, `directory`); add:

```php
public function testAnAbsoluteDirectoryIsKeptAsGiven(): void
{
    $choice = ServerChoice::pick([$this->link(1, 'example.test', '/var/www/example.test/current', 'example-ec2')], null);

    $this->assertSame('/var/www/example.test/current', $choice->directory);
    $this->assertSame('example-ec2', $choice->sshAlias);
}

public function testNoLinkOfEitherKindSaysSo(): void
{
    $this->assertSame('no domain or hostname is linked to it', ServerChoice::pick([], null)->problem);
}
```

- [ ] **Step 2: Run the suite to see it fail**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -20`
Expected: `StandardsServerChoiceTest` fails on undefined key `dh_site_domain_id` / wrong message.

- [ ] **Step 3: Rewrite `ServerChoice` for the new keys** (docblock types, `pick`, `reach`, `directoryOf`), and in `SiteStore::forScanner` build `$links[...]` with `link_id`, `where`, `path_prefix`, `directory` (from `web_directory`), `ssh_alias`, `php_version`; keep `php_version` as an extra key the choice ignores. Update the two places that compare `$link['dh_site_domain_id']` to use `link_id`.

- [ ] **Step 4: Run the suite and PHPStan**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -5 && ~/work/rob/standards-mcp/run-phpstan.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -3`
Expected: all tests pass; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add classes/Inventory/Standards/ServerChoice.php classes/Inventory/Standards/SiteStore.php Tests/Unit/StandardsServerChoiceTest.php
git commit -m "Let ServerChoice pick from links of any kind" -m "The link array no longer names DreamHost columns, so a hostname on another host can be the server the scanner asks." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: `HostKind`, `SiteFacts` and `HostChecks` answer for EC2

**Files:**
- Create: `classes/Inventory/Standards/HostKind.php`
- Modify: `classes/Inventory/Standards/SiteFacts.php`
- Modify: `classes/Inventory/Standards/HostChecks.php`
- Test: `Tests/Unit/StandardsHostChecksTest.php`

**Interfaces:**
- `enum HostKind: string { case DreamHost = 'dreamhost'; case Ec2 = 'ec2'; case None = 'none'; }` with `public function heading(): string` returning `On DreamHost`, `On EC2`, `No host linked yet`.
- `SiteFacts::__construct(array $domains, ?string $checkoutPath, ?string $githubRepo, ?array $testDomain = null, HostKind $host = HostKind::DreamHost)`. For an EC2 site each `$domains` entry carries the same keys as today: `domain`, `php_version`, `username` (null), `ssh_alias` (the server's), `login_confirmed_on` (the server's).
- `HostChecks::NAMES` gains `'host_php_probe'`; `HostChecks::answer` answers it:
  - `HostKind::Ec2`: Pass with `''` when the server link's `php_version` is non-null (first `$domains` entry; `SiteStore`/`Board` put the server first); else Fail `the sweep has not read a PHP version from <hostname> yet`; no domains → Fail `no hostname linked yet`.
  - `HostKind::DreamHost`: Pass with `DreamHost's panel says which PHP serves it`.
  - `HostKind::None`: Fail `no domain or hostname linked yet`.
- `shortOf()` for a null version: `HostKind::Ec2` → `new CheckResult(Outcome::Unknown, "the sweep could not read which PHP serves {$domain}")`; DreamHost unchanged.
- `hasDomain()` detail for `HostKind::Ec2` with no domains: `no hostname linked yet`.

- [ ] **Step 1: Write the failing tests** in `StandardsHostChecksTest`:

```php
public function testAnEc2HostnameWithoutAVersionIsUnknownNotFailed(): void
{
    $facts = new SiteFacts([$this->ec2Domain(null, '2026-11-08')], 'rob/example', 'example/example', null, HostKind::Ec2);

    $answer = $this->answer('dh_serves_php', '8.5', $facts);
    $this->assertSame(Outcome::Unknown, $answer->outcome);
    $this->assertSame('the sweep could not read which PHP serves example.test', $answer->detail);
    $this->assertSame(Outcome::Fail, $this->answer('host_php_probe', null, $facts)->outcome);
}

public function testAnEc2HostnameNeverSweptIsNotReachable(): void
{
    $facts = new SiteFacts([$this->ec2Domain(null, null)], null, null, null, HostKind::Ec2);

    $this->assertFalse(HostChecks::reachable($facts));
    $this->assertSame('alias found, login not proven yet: example.test', $this->answer('dh_ssh_login', null, $facts)->detail);
}

public function testASweptEc2HostnameServingTheTargetPasses(): void
{
    $facts = new SiteFacts([$this->ec2Domain('8.5.1', '2026-11-08')], 'rob/example', 'example/example', null, HostKind::Ec2);

    $this->assertTrue(HostChecks::reachable($facts));
    $this->assertSame(Outcome::Pass, $this->answer('dh_serves_php', '8.5', $facts)->outcome);
    $this->assertSame(Outcome::Pass, $this->answer('host_php_probe', null, $facts)->outcome);
}

public function testADreamHostSitePassesTheProbeStepBecauseThePanelSays(): void
{
    $facts = new SiteFacts([$this->domain('8.5', 'b.rn', '2026-10-01')], 'rob/example', 'example/example');

    $answer = $this->answer('host_php_probe', null, $facts);
    $this->assertSame(Outcome::Pass, $answer->outcome);
    $this->assertSame("DreamHost's panel says which PHP serves it", $answer->detail);
}
```

with helper `ec2Domain(?string $php, ?string $confirmed): array` returning `['domain' => 'example.test', 'php_version' => $php, 'username' => null, 'ssh_alias' => 'example-ec2', 'login_confirmed_on' => $confirmed]`.

- [ ] **Step 2: Run to see them fail**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com 2>&1 | grep -E "Tests:|Error|Fail" | head`
Expected: errors for missing `HostKind` / unknown check.

- [ ] **Step 3: Implement** `HostKind`, the `SiteFacts` parameter, `host_php_probe`, and the two detail changes. Update the class docblock of `HostChecks`: the `dh_` names are historical and mean "answered by mg from host facts".

- [ ] **Step 4: Run the suite and PHPStan**

Run: as Task 2 Step 4. Expected: pass, `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add classes/Inventory/Standards/HostKind.php classes/Inventory/Standards/SiteFacts.php classes/Inventory/Standards/HostChecks.php Tests/Unit/StandardsHostChecksTest.php
git commit -m "Answer the host steps for a site on EC2" -m "The same five steps, from swept facts; a PHP version the sweep could not read is unknown, never a pass. A new check, host_php_probe, says whether the web PHP can be read at all." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: `SiteStore` keeps EC2 servers, hostnames and links

**Files:**
- Create: `classes/Inventory/Standards/LinkKindRule.php`
- Modify: `classes/Inventory/Standards/Names.php` (add `isServerDirectory`)
- Modify: `classes/Inventory/Standards/SiteStore.php`
- Test: `Tests/Unit/StandardsLinkKindRuleTest.php`, `Tests/Unit/StandardsNamesTest.php`

**Interfaces:**
- `LinkKindRule::problem(?HostKind $existing, HostKind $adding): ?string`: null when `$existing` is null, `HostKind::None`, or equals `$adding`; otherwise exactly `This site already has a DreamHost link; a site's links are all of one kind.` or `This site already has an EC2 link; a site's links are all of one kind.`
- `Names::isServerDirectory(string $s): bool`: `\A/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\z` and no `..` segment.
- `SiteStore` new methods:
  - `addServer(string $sshAlias, string $label): void` (alias must match `\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z`, unique; refusal `An EC2 server with alias … is already entered.`)
  - `addDomain(string $sshAlias, string $domain, string $directory): void` (server must exist; domain unique across `ec2_domains` and absent from `dh_domains`: `… is a DreamHost domain; it cannot also be an EC2 hostname.`; directory must pass `isServerDirectory`: `"…" is not an absolute directory on the server. Write it like /var/www/example.test/current.`)
  - `serversForSweep(): list<array{ssh_alias: string, domains: list<array{domain: string, directory: string}>}>`
  - `hostOf(int $siteId): HostKind` (private): `Ec2` if any `ec2_site_domains` row, `DreamHost` if any `dh_site_domains` row, else `None`.
- Changed behaviour:
  - `link()` resolves `$domain` in `dh_domains` then `ec2_domains`. Neither: `No DreamHost domain or EC2 hostname is called {$domain}.` Then applies `LinkKindRule` and inserts into the matching link table.
  - `unlink()`, `linkId()`, `namedLink()`, `set()` for `server`/`test` work on the site's kind; the std_sites columns written are `server_site_domain_id`/`test_site_domain_id` for DreamHost and `server_ec2_site_domain_id`/`test_ec2_site_domain_id` for EC2.
  - `forScanner()` adds EC2 links from `ec2_site_domains` joined to `ec2_domains`, `ec2_servers`, and the open `ec2_domain_states` row (`php_version`), in the Task 2 shape with `directory` = `ec2_domains.directory`; the named-server/test ids come from the EC2 columns for EC2 sites. Output shape unchanged.

- [ ] **Step 1: Write failing tests**

`StandardsLinkKindRuleTest`: `testAFirstLinkOfAnyKindIsFine`, `testTheSameKindAgainIsFine`, `testMixingKindsIsRefusedWithTheExistingKindNamed` asserting the two exact strings above.
`StandardsNamesTest`: add `testServerDirectoryMustBeAbsoluteAndClean` with `/var/www/example.test/current` true, `var/www` false, `/var/../etc` false, `/var/www/` false.

- [ ] **Step 2: Run to see them fail** (as Task 2 Step 4, first command). Expected: class/method not found.

- [ ] **Step 3: Implement `LinkKindRule`, `Names::isServerDirectory`, and the `SiteStore` changes.** Keep each SQL string in the style of the existing ones. `forScanner()` queries DH links and EC2 links separately and merges by `std_site_id`; a site cannot have both.

- [ ] **Step 4: Run the suite and PHPStan** (as Task 2 Step 4). Expected: pass, `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add classes/Inventory/Standards/LinkKindRule.php classes/Inventory/Standards/Names.php classes/Inventory/Standards/SiteStore.php Tests/Unit/StandardsLinkKindRuleTest.php Tests/Unit/StandardsNamesTest.php
git commit -m "Keep EC2 servers and hostnames beside the DreamHost links" -m "A site links to a hostname of either kind, never both; its server and test domain are named the same way whichever kind it has." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: `site.sh` commands for EC2

**Files:**
- Modify: `classes/Inventory/Standards/SiteCommand.php`
- Modify: `tools/site-standards/site.sh` (usage comment only)
- Test: `Tests/Unit/StandardsSiteCommandTest.php`

**Interfaces:**
- New commands in `COMMANDS`:
  - `ec2-server-add`: required `alias`, optional `label`
  - `ec2-domain-add`: required `alias`, `domain`, `directory`
  - `ec2-servers`: none
- `run()` answers: `Added EC2 server {$alias}.`, `Added {$domain} on {$alias}, served from {$directory}.`, and for `ec2-servers` the JSON of `SiteStore::serversForSweep()` with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`.
- Class docblock lists the three commands and says `site-link --domain=` takes a DreamHost domain or an EC2 hostname.

- [ ] **Step 1: Write failing tests**

```php
public function testEc2ServerAddTakesAnAliasAndAnOptionalLabel(): void
{
    $parsed = SiteCommand::parse(['ec2-server-add', '--alias=example-ec2', '--label=Example EC2']);
    $this->assertSame(['alias' => 'example-ec2', 'label' => 'Example EC2'], $parsed['options']);
}

public function testEc2DomainAddNeedsAllThree(): void
{
    $this->expectExceptionMessage('ec2-domain-add needs --directory=.');
    SiteCommand::parse(['ec2-domain-add', '--alias=example-ec2', '--domain=example.test']);
}
```

- [ ] **Step 2: Run to see them fail.** Expected: `Unknown command: ec2-server-add`.
- [ ] **Step 3: Implement** the three commands in `parse`'s table and `run`'s switch; update `site.sh`'s comment with one example of each.
- [ ] **Step 4: Run the suite and PHPStan.** Expected: pass.
- [ ] **Step 5: Commit**

```bash
git add classes/Inventory/Standards/SiteCommand.php tools/site-standards/site.sh Tests/Unit/StandardsSiteCommandTest.php
git commit -m "Enter EC2 servers and hostnames with site.sh" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: The board groups sites by host

**Files:**
- Create: `classes/Inventory/Standards/SiteGroups.php`
- Modify: `classes/Inventory/Standards/Board.php` (`standards()`, `site()`)
- Modify: `templates/admin/dh/standards.tpl.php`
- Test: `Tests/Unit/StandardsSiteGroupsTest.php`

**Interfaces:**
- `Board::site()` returns three more keys: `host: string` (`HostKind` value), `server_where: ?string` (`"<alias> in <directory>"` for EC2, null for DreamHost), `web_php: ?string` (EC2: open state's `php_version` of the server hostname; null otherwise). It builds `SiteFacts` from `ec2_site_domains` when the site has no DH link, in the Task 3 shape, with the named server link first and `HostKind::Ec2`; `HostKind::None` when neither.
- `SiteGroups::of(array $sites): list<array{kind: HostKind, heading: string, sites: list<array<mixed>>}>` — groups by `$site['host']` in the order DreamHost, Ec2, None; omits empty groups; keeps each group's sites in the order given.
- `Board::standards()` returns `'groups' => SiteGroups::of($sites)` beside the existing `'sites'` (ideas still read `$sites`).
- Template: for each group, `<h4 class="StdHost"><?= $h($group['heading']) ?></h4>` then the existing `<details>` loop over `$group['sites']`. The footer sentence for an EC2 site starts `Its server is <code>alias</code> in <code>directory</code>;` then `the web PHP is <code>8.1.2</code>;` or `the sweep could not read which PHP serves it;` then the existing server-runs / last-checked text. Intro gains the sentence `Sites on EC2 are here too; their servers are entered with site.sh.` CSS: `.StdHost { margin: 0.8em 0 0.2em; font-size: 0.95em; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: normal; }` in `wwwroot/css/styles.css`.

- [ ] **Step 1: Write failing tests** in `StandardsSiteGroupsTest`:

```php
public function testGroupsFollowHostOrderAndSkipEmptyOnes(): void
{
    $groups = SiteGroups::of([
        ['shortcode' => 'ex', 'host' => 'ec2'],
        ['shortcode' => 'mg', 'host' => 'dreamhost'],
    ]);
    $this->assertSame(['On DreamHost', 'On EC2'], array_column($groups, 'heading'));
    $this->assertSame('mg', $groups[0]['sites'][0]['shortcode']);
}

public function testAnUnlinkedSiteHasItsOwnHeadingLast(): void
{
    $groups = SiteGroups::of([['shortcode' => 'new', 'host' => 'none'], ['shortcode' => 'mg', 'host' => 'dreamhost']]);
    $this->assertSame('No host linked yet', $groups[1]['heading']);
}
```

- [ ] **Step 2: Run to see them fail.** Expected: class not found.
- [ ] **Step 3: Implement `SiteGroups`, the `Board` changes and the template.** Template and CSS are out of phpcs scope; keep the template's existing indentation style.
- [ ] **Step 4: Run the suite, PHPStan and phpcs**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -3 && ~/work/rob/standards-mcp/run-phpstan.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -2 && ~/work/rob/standards-mcp/run-phpcs.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -2`
Expected: pass, no errors, no style violations.

- [ ] **Step 5: Commit in two pieces** (database layer, then template):

```bash
git add classes/Inventory/Standards/SiteGroups.php classes/Inventory/Standards/Board.php Tests/Unit/StandardsSiteGroupsTest.php
git commit -m "Read a site's host facts from either link kind" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
git add templates/admin/dh/standards.tpl.php wwwroot/css/styles.css
git commit -m "Group the standards board by host" -m "One board, sites under On DreamHost, On EC2 and No host linked yet. No badges; the three marks and no others." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: The probe step and mg's own probe file

**Files:**
- Create: `tools/site-standards/steps/template/groundwork/web_php_version_readable.md`
- Create: `wwwroot/_php_version.php`
- Modify: `tools/site-standards/NAMES.md`

**Interfaces:**
- Step frontmatter: `title: Web PHP version readable`, `position: 25`, `status: idea`, `whose_move: agent`, `check: host_php_probe`. Body explains the CLI-vs-web gap and the loopback probe; "How to do it" names the file, the `REMOTE_ADDR` rule, and that a bootstrap which runs on every request must let `/_php_version.php` through.
- `wwwroot/_php_version.php`: `declare(strict_types=1)`; when `$_SERVER['REMOTE_ADDR']` is `127.0.0.1` or `::1`, `header('Content-Type: text/plain'); echo PHP_VERSION;` else `http_response_code(404)`. No `prepend.php` include: the file stands alone, uses no globals, and must pass phpcs and PHPStan as-is.
- `NAMES.md` gains a section `### /_php_version.php` under a new heading `## Paths at a site's web root`: read by the EC2 sweep (`tools/ec2-sweep/deets.sh`), answers only to loopback.

- [ ] **Step 1: Write the three files.**
- [ ] **Step 2: Verify the step file parses and the probe behaves**

Run: `php -r 'require "classes/Mlaphp/Autoloader.php"; spl_autoload_register([new Mlaphp\Autoloader(), "load"]); $t = Inventory\Standards\StepTree::read("tools/site-standards/steps"); echo count($t->standards), " standards\n";'` → prints `2 standards` with no exception.
Run: `REMOTE_ADDR=10.0.0.9 php -r '$_SERVER["REMOTE_ADDR"]=getenv("REMOTE_ADDR"); include "wwwroot/_php_version.php"; echo "\n", http_response_code();'` → `404`; with `REMOTE_ADDR=127.0.0.1` → a version string.
Run phpcs and PHPStan (Task 6 Step 4 commands). Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add tools/site-standards/steps/template/groundwork/web_php_version_readable.md tools/site-standards/NAMES.md wwwroot/_php_version.php
git commit -m "Add the step that lets localhost read the web PHP" -m "php -v is not the PHP that serves the site. A probe answered only to loopback is the one deterministic way to read the web version on a server with no panel; mg carries it too so the step can be proven." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: The EC2 sweep payload and importer

**Files:**
- Create: `classes/Inventory/Ec2/SweepPayload.php`
- Create: `classes/Inventory/Ec2/SweepImporter.php`
- Create: `tools/import_ec2_sweep.php`
- Test: `Tests/Unit/Ec2SweepPayloadTest.php`, `Tests/Unit/SpanPlanTest.php` (one test added)

**Interfaces:**
- Document shape (one server per document):
  ```json
  {"ssh_alias": "example-ec2", "swept_at_utc": "2026-11-08 01:02:03",
   "web_server": "Apache/2.4.52 (Ubuntu)", "cli_php": "8.1.2",
   "domains": [{"domain": "example.test", "directory": "/var/www/example.test/current",
                "php_version": "8.1.2", "probe_route": "http_forwarded", "probe_problem": "",
                "directory_exists": true, "is_git_checkout": true,
                "head_commit": "<40 hex>", "head_branch": "master",
                "deployed_at_utc": "2026-11-01 10:00:00"}]}
  ```
- `Inventory\Ec2\SweepPayload::fromArray(array $data): self` with readonly public `sshAlias`, `sweptAtUtc`, `webServer`, `cliPhp`, `domains` (list of the record shape above, typed). Uses `Inventory\Json`. Refuses (as `Inventory\SweepFormatException`): a missing field; `php_version` not matching `/\A\d+\.\d+(?:\.\d+)?\z/` when non-null (`domains[0].php_version: "<!DOCTYPE html>…" is not a PHP version`); `probe_route` not one of `https`, `http_forwarded`, null; `head_commit` not 40 hex when non-null; a `directory` failing `Names::isServerDirectory`; duplicate `domain`s; any field name matching `SweepPayload::SECRET_NAME`-style pattern (copy the regex) anywhere in the document.
- `Inventory\Ec2\SweepImporter::__construct(\PDO $pdo)`, `import(SweepPayload $sweep, bool $dryRun = false): array<string, array{new:int, opened:int, closed:int, unchanged:int}>`. Inside one transaction: refuse (as `Inventory\SweepRefusedException`) an alias not in `ec2_servers` (`no EC2 server has the alias …; enter it with site.sh ec2-server-add`), a hostname not in `ec2_domains` for that server (`… is not a hostname of server …`), or a directory that differs from mg's (`mg has … served from X, the sweep says Y`). Insert `ec2_sweeps`; sync `ec2_domain_states` with `SpanPlan::diff` over `OpenSpan`/`SweptSpan` keyed by `ec2_domain_id`, attrs `php_version, probe_route, directory_exists, is_git_checkout, head_commit, head_branch, deployed_at_utc`; set `login_confirmed_on = CURDATE()` `WHERE ec2_server_id = ? AND login_confirmed_on IS NULL`. Stats family name: `hostname states`.
- `tools/import_ec2_sweep.php`: STDIN JSON, `--dry-run`, prints `IMPORTED sweep of <alias> at <swept_at_utc>` (or `DRY RUN (rolled back) …`) then one stats line like `import_dh_sweep.php`.

- [ ] **Step 1: Write failing tests**

`Ec2SweepPayloadTest` (fixture helper `sweep(array $overrides = [])` returning the document above with recursive merge, like `SweepPayloadTest`):
- `testAcceptsAWellFormedDocument` (reads back `sshAlias`, one domain, `php_version`)
- `testRefusesABodyThatIsNotAVersion` (`php_version` → `<!DOCTYPE html>`; expects `SweepFormatException` with message containing `is not a PHP version`)
- `testANullVersionIsAllowedWithAProblem` (`php_version` null, `probe_problem` `redirected to /system/`)
- `testRefusesARelativeDirectory`
- `testRefusesAFieldThatLooksLikeASecret` (`domains[0].db_password`)
`SpanPlanTest`: `testTwoNullsAreTheSameFact` — an open span with `['php_version' => null]` and a swept span with the same key and `['php_version' => null]` give `unchanged === 1`, no opens, no closes.

- [ ] **Step 2: Run to see them fail.** Expected: class not found (payload), and the `SpanPlan` test either passes already (then keep it as a pin) or fails.
- [ ] **Step 3: Implement the payload, the importer and the entry script.** The importer's SQL follows `SweepImporter::syncSpans` but lives in `Ec2\SweepImporter` (private `syncStates`), scoped by `ec2_server_id`.
- [ ] **Step 4: Run the suite, PHPStan, phpcs.** Expected: clean.
- [ ] **Step 5: Commit in two pieces**

```bash
git add classes/Inventory/Ec2/SweepPayload.php Tests/Unit/Ec2SweepPayloadTest.php Tests/Unit/SpanPlanTest.php
git commit -m "Read an EC2 sweep document and refuse a bad one" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
git add classes/Inventory/Ec2/SweepImporter.php tools/import_ec2_sweep.php
git commit -m "Import an EC2 sweep into hostname states" -m "Open and close facts per hostname the way the DreamHost importer does; the first sweep of a server proves its ssh login, once." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: The sweep scripts

**Files:**
- Create: `tools/ec2-sweep/deets.sh`
- Create: `tools/ec2-sweep/install.sh`
- Create: `tools/ec2-sweep/sweep.sh`
- Create: `tools/ec2-sweep/README.md`

**Interfaces:**
- `deets.sh HOSTNAME=DIRECTORY [HOSTNAME=DIRECTORY …]` runs on the server, bash 5, needs `curl`, `git`, `python3` (for JSON encoding; Ubuntu 22.04 has it). Prints exactly one JSON object (Task 8 shape) on stdout; diagnostics on stderr. Per pair:
  - probe: `curl -s --max-time 10 --resolve "$host:443:127.0.0.1" "https://$host/_php_version.php"`; if the body matches `^[0-9]+\.[0-9]+(\.[0-9]+)?$` → `probe_route: https`; else `curl -s --max-time 10 -H "Host: $host" -H "X-Forwarded-Proto: https" "http://127.0.0.1/_php_version.php"` → `http_forwarded`; else `php_version: null`, `probe_problem` = `curl: <exit code>` or `http <status>` or `body is not a version`. Never follows redirects (`-L` absent); a `3xx` is a problem.
  - `directory_exists`: `[ -d "$dir" ]`; `is_git_checkout`: `git -C "$dir" rev-parse --is-inside-work-tree` succeeds; `head_commit`/`head_branch`: `git --no-optional-locks -C "$dir" rev-parse HEAD` and `symbolic-ref --short -q HEAD` (null on detached HEAD).
  - `deployed_at_utc`: `date -u -d @"$(stat -c %Y "$dir")" '+%F %T'` on the directory path itself (not dereferenced: `stat` on a symlink gives the link's own time; use `stat -c %Y` without `-L`).
  - server-level: `web_server` from the `Server:` header of the first probe response (null if none), `cli_php` from `php -r 'echo PHP_VERSION;'` (null if no `php`).
- `install.sh ALIAS` copies `deets.sh` to `ALIAS:~/.local/bin/sweep_ec2_deets_for_mg.sh` (`ssh ALIAS mkdir -p ~/.local/bin`, `scp`), writes `~/.local/bin/sweep_ec2_deets_for_mg.INSTALLED_FROM` with branch, commit, date (the journal-sync pattern), and runs `bash -n` on the remote copy.
- `sweep.sh [--dry-run] [alias …]`: `site.sh ec2-servers` → for each server (or the named ones) `ssh -o BatchMode=yes -o ConnectTimeout=15 ALIAS '~/.local/bin/sweep_ec2_deets_for_mg.sh' host=dir …` → pipe to `ssh mg "/usr/local/php85/bin/php ~/mg.robnugen.com/tools/import_ec2_sweep.php [--dry-run]"`; `SWEEP_SSH`, `SWEEP_SITE_CMD`, `SWEEP_IMPORT_CMD` env overrides, like `scan.py`'s. A server that does not answer is reported on stderr and skipped; the others still import. Exit 1 if any server failed.
- `README.md`: the three commands, the probe routes, and that nothing schedules it.

- [ ] **Step 1: Write the four files.**
- [ ] **Step 2: Verify locally with a fake server**

Run: `bash -n tools/ec2-sweep/*.sh && shellcheck tools/ec2-sweep/*.sh` (if shellcheck is installed; otherwise `bash -n` only).
Run `deets.sh` on Lemur 13 itself against a directory that is a git checkout and a hostname nothing serves: `tools/ec2-sweep/deets.sh example.test=$HOME/work/rob/mg.robnugen.com | python3 -I -m json.tool`
Expected: valid JSON; `php_version: null`, `probe_problem` starts with `curl:`, `is_git_checkout: true`, `head_commit` 40 hex.
Then feed it to the payload class: `tools/ec2-sweep/deets.sh example.test=/tmp | php -r 'require "classes/Mlaphp/Autoloader.php"; spl_autoload_register([new Mlaphp\Autoloader(), "load"]); $d = json_decode(stream_get_contents(STDIN), true); $d["ssh_alias"] = "example-ec2"; Inventory\Ec2\SweepPayload::fromArray($d); echo "payload ok\n";'` → `payload ok`.

- [ ] **Step 3: Commit**

```bash
git add tools/ec2-sweep
git commit -m "Sweep an EC2 server for the facts the board needs" -m "deets.sh runs on the server by name with hostname=directory pairs and reads the web PHP over loopback, trying direct TLS and then plain HTTP with the forwarded-proto header, because TLS may end at a load balancer. install.sh pins a copy on the server; sweep.sh drives it from Lemur 13 and streams the answer to the importer." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: The scanner and three checks accept an absolute server directory

**Files:**
- Modify: `tools/site-standards/scan.py:44`
- Modify: `tools/site-standards/checks/js_soaked_on_production.sh`, `js_deprecations_clear.sh`, `suite_passes_on_php.sh` (the `dir` regex line in each)
- Modify: `tools/site-standards/lib.sh` (add `need_server_dir`)

**Interfaces:**
- `scan.py`: `SERVER_DIRECTORY = re.compile(r"\A/?[A-Za-z0-9_][A-Za-z0-9._-]*(?:/[A-Za-z0-9._-]+)*\Z")`; the `..` test stays.
- `lib.sh`: `need_server_dir VALUE` sets `SERVER_DIR` or stops with `unknown "the server's directory is not one this check will use"`; accepts the same pattern (optional leading `/`, no `..`). The three checks call it instead of their inline regex.

- [ ] **Step 1: Change the regexes and add the helper.**
- [ ] **Step 2: Verify with a fake ssh**

Create `$SCRATCH/fake-ssh.sh` that prints `abc1234 HEAD@{1757000000}: checkout` for any arguments; then:
`SCAN_SSH=$SCRATCH/fake-ssh.sh SITE_SERVER_SSH_ALIAS=example-ec2 SITE_SERVER_DIRECTORY=/var/www/example.test/current tools/site-standards/checks/js_soaked_on_production.sh ~/work/rob/mg.robnugen.com 20; echo "exit $?"`
Expected: a line about production having run the commit (or the no-libraries pass), never `the server's directory is not one this check will use`. Repeat with `SITE_SERVER_DIRECTORY=/var/../etc` → that refusal, exit 2.
`python3 -c 'import re,sys; sys.path.insert(0,"tools/site-standards"); import scan; print(bool(scan.SERVER_DIRECTORY.match("/var/www/x")), bool(scan.SERVER_DIRECTORY.match("x/wwwroot")))'` → `True True`.

- [ ] **Step 3: Commit**

```bash
git add tools/site-standards/scan.py tools/site-standards/lib.sh tools/site-standards/checks/js_soaked_on_production.sh tools/site-standards/checks/js_deprecations_clear.sh tools/site-standards/checks/suite_passes_on_php.sh
git commit -m "Let the scanner reach a server by absolute directory" -m "DreamHost directories are relative to the user's home; an EC2 checkout is named in full. One rule in lib.sh, used by the checks that ask the server anything." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Docs, gates, hand-off

**Files:**
- Modify: `CLAUDE.md` (the "Site standards" section: tables line and the EC2 sentence)
- Modify: `docs/superpowers/specs/2026-11-08-ec2-sites-on-board-design.md` (one line: the three checks also learned the absolute directory)

- [ ] **Step 1: Update `CLAUDE.md`**: in the tables bullet add `ec2_servers`, `ec2_domains`, `ec2_sweeps`, `ec2_domain_states` and `ec2_site_domains` (`db_schemas/50_ec2_sites/`); replace the sentence written at 0.67.0's BEGIN with: `Sites on EC2 are entered with site.sh ec2-server-add / ec2-domain-add, linked with site-link, swept with tools/ec2-sweep/sweep.sh (deets.sh installed on the server by install.sh), and shown on the same board under their own heading. The web PHP is read through wwwroot/_php_version.php over loopback; a site whose bootstrap runs on every request must let that path through.`
- [ ] **Step 2: Run every gate**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -3; ~/work/rob/standards-mcp/run-phpcs.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -2; ~/work/rob/standards-mcp/run-phpstan.sh ~/work/rob/mg.robnugen.com 2>&1 | tail -2` and `check_tests(repo="mg")`.
Expected: all green; `status: pass`.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md docs/superpowers/specs/2026-11-08-ec2-sites-on-board-design.md
git commit -m "Say in CLAUDE.md how EC2 sites reach the board" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

- [ ] **Step 4: Hand off.** Do not close the bubble or push. Tell Rob what is left to him, in order: deploy the branch; apply migration 50 at `/admin/migrate_tables.php`; `site.sh ec2-server-add`, `ec2-domain-add` and `site-link` for the real sites (names live in his memory, not here); `install.sh <alias>` on each server; `sweep.sh --dry-run`, then for real; the probe file in each client site (their agent's job); `scan.sh`. The bubble closes with `git close-bubble` once he says so.
