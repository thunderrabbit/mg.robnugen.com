# DH Standards Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A calm page at `/admin/dh/standards.php` showing, for `mg` and one other fork, which written-up steps each has done, driven by checks, with per-site notes read from mg issues.

**Architecture:** Steps are Markdown files in this repo, read from `master`. A bash scanner on Lemur 13 runs check scripts against each site's checkout and streams one JSON document over `ssh mg` to a PHP importer, the same shape as the DreamHost sweep import. The page combines the imported results with checks mg answers from its own tables and with issues found by title in each site's project. The rules that decide a mark are pure classes with Unit tests; the database code is verified by hand.

**Tech Stack:** PHP 8.5 (no runtime dependencies), MySQL 8, bash + `python3 -I` on Lemur 13, Codeception Unit suite, phpcs PSR-12, PHPStan level 10, standards-mcp runner images.

**Spec:** `docs/superpowers/specs/2026-11-06-dh-standards-design.md`. Read it first; this plan argues from it.

## Global Constraints

- Work on branch `dh-standards`. Never check out or merge into `master`. The bubble opens with Task 1's BEGIN commit and closes with `git close-bubble` (Task 10).
- Every commit must pass `.githooks/pre-commit` (phpcs on staged PHP, PHPStan level 10 on the staged tree). `tools/` is in both scopes. No baseline, no `--no-verify`.
- Before reporting any task done, run `check_tests(repo="mg")` (or `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`).
- Commit messages: subject ≤50 chars, imperative, capitalised, no period; body via extra `-m` flags explaining why; never a heredoc; end with `-m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"`. One commit per class with its test; SQL, PHP, templates and docs never share a commit.
- This repo is public. Tests, fixtures, comments and step files use `mg` or made-up names (`example-games`, `example.test`). No real shortcode other than `mg`, no DreamHost username, no per-site progress in any committed file.
- Primary key names are unique across the project. Migration files start with a letter prefix (`a_`, `b_`), never a digit.
- Marks are exactly three: `✓` (green), `ⓘ`, `😂`. No warning triangle, no red, no count styled as a problem.
- A step is ticked only by a check. The one exception is an issue in status NAh.
- The scanner never writes to a repo it checks. Nothing adds a credential, an API route, or a secret on Lemur 13.
- All new PHP classes live in `classes/Inventory/Standards/`, namespace `Inventory\Standards`, `declare(strict_types=1)`, `final`. DB rows are read with `\Db\Rows` and `\Db\RowValue`, as `Inventory\Browse` does.

## Review Focus

Conditions the spec implies that are most likely to bite, each pinned by a test in the task named:

1. **A site with no domain.** The ssh check must fail, not pass because there were no users to check; the mark is the face. (Task 3, `HostChecksTest`)
2. **A scan run while mg's checkout is on a topic branch.** mg is recorded as skipped and its earlier results stay current; they are not closed as "gone". (Task 5, `ScanPayloadTest` for the payload shape; Task 5 hand check for the spans)
3. **A step file renamed or removed.** The step is retired, not deleted; an issue still carrying the old title shows in the stray list, not as an error. (Task 4 `StepTreeTest` for the tree; Task 7 `SiteReportTest` for the stray)
4. **Two issues with the same note title in one project.** Both are shown; nothing throws; NAh on either counts. (Task 7, `SiteReportTest`)
5. **Nothing imported yet.** No scan, no sites, or a site never scanned: the page renders, every scanner step reads "not checked yet" with the info circle. (Task 7, `SiteReportTest`; Task 8 hand check)

---

## File Structure

| File | Responsibility |
|---|---|
| `db_schemas/45_site_standards/a_create_std_standards.sql` | `std_standards`, `std_tracks`, `std_steps` |
| `db_schemas/45_site_standards/b_create_std_sites.sql` | `std_sites`, `dh_site_domains` |
| `db_schemas/45_site_standards/c_create_std_scans.sql` | `std_scans`, `std_scan_sites`, `std_step_states` |
| `db_schemas/45_site_standards/d_add_issue_status_nah.sql` | the NAh status row |
| `classes/Inventory/Standards/Names.php` | name rules: step name, shortcode, note title |
| `classes/Inventory/Standards/{StepStatus,WhoseMove,Outcome,Mark}.php` | string-backed enums |
| `classes/Inventory/Standards/StepTree.php` (+ `StepDef`, `TrackDef`, `StandardDef`, `StandardsFormatException`) | parse the step files into a tree, refuse bad ones |
| `classes/Inventory/Standards/HostChecks.php` (+ `SiteFacts`, `CheckResult`) | the checks mg answers itself |
| `classes/Inventory/Standards/SiteStanding.php` (+ `Standing`) | the rule for a site's mark and summary |
| `classes/Inventory/Standards/ScanPayload.php` (+ `ScanRefusedException`) | validate the scanner's JSON document |
| `classes/Inventory/Standards/StandardsImporter.php` | write a payload in one transaction |
| `classes/Inventory/Standards/SiteStore.php` | hand-kept site facts: add, set, link, list |
| `classes/Inventory/Standards/SiteReport.php` (+ `StepLine`, `NoteIssue`) | assemble one site's view from plain rows; pure |
| `classes/Inventory/Standards/Board.php` | the page's queries; hands rows to `SiteReport` |
| `tools/import_site_standards.php` | CLI: JSON on STDIN → importer, `--dry-run` |
| `tools/site_standards.php` | CLI: `sites`, `site-add`, `site-set`, `site-link`, `site-unlink` |
| `tools/site-standards/scan.sh`, `site.sh`, `lib.sh` | Lemur 13: run checks and push; wrapper for the site CLI |
| `tools/site-standards/checks/*.sh` | one script per scanner check |
| `tools/site-standards/steps/template/**` | the template standard's step files |
| `wwwroot/admin/dh/standards.php`, `templates/admin/dh/standards.tpl.php` | the page |
| `templates/admin/dh/_nav.tpl.php`, `wwwroot/admin/dh/domain.php`, `templates/admin/dh/domain.tpl.php`, `wwwroot/css/styles.css` | nav link, domain panel, marks' styles |
| `Tests/Unit/Standards{Names,StepTree,HostChecks,SiteStanding,ScanPayload,SiteReport}Test.php` | Unit tests |

---

### Task 1: Open the bubble and write the migration

**Files:**
- Modify: `version.php` (`SEMVER` `"0.58.0"` → `"0.59.0"`, nothing else)
- Create: the four files under `db_schemas/45_site_standards/`

**Interfaces:**
- Produces: the tables below. Later tasks use these exact table and column names.

- [ ] **Step 1: BEGIN commit.** Bump `SEMVER` only. Commit: `BEGIN standards page on the DH dashboard`.

- [ ] **Step 2: Write the four SQL files** with these definitions. All tables `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin`, matching `39_dreamhost_inventory`. Each file opens with a comment saying what the tables hold and that nothing references a sweep id.

```sql
-- a_create_std_standards.sql
CREATE TABLE std_standards (
    std_standard_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(32) NOT NULL,
    name            VARCHAR(64) NOT NULL,
    UNIQUE KEY uq_standard_slug (slug)
);
CREATE TABLE std_tracks (
    std_track_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_standard_id INT UNSIGNED NOT NULL,
    slug            VARCHAR(32) NOT NULL,
    name            VARCHAR(64) NOT NULL,
    position        SMALLINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_track (std_standard_id, slug),
    FOREIGN KEY (std_standard_id) REFERENCES std_standards(std_standard_id)
);
CREATE TABLE std_steps (
    std_step_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_track_id INT UNSIGNED NOT NULL,
    slug         VARCHAR(64) NOT NULL,
    position     SMALLINT UNSIGNED NOT NULL,
    title        VARCHAR(160) NOT NULL,
    explanation  TEXT NOT NULL,
    how_to       MEDIUMTEXT NOT NULL,
    status       ENUM('idea','proven') NOT NULL,
    whose_move   ENUM('rob','agent') NOT NULL,
    check_name   VARCHAR(64) NOT NULL,
    check_param  VARCHAR(32) NULL,
    retired_on   DATE NULL,
    UNIQUE KEY uq_step (std_track_id, slug),
    FOREIGN KEY (std_track_id) REFERENCES std_tracks(std_track_id)
);

-- b_create_std_sites.sql
CREATE TABLE std_sites (
    std_site_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_standard_id INT UNSIGNED NOT NULL,
    shortcode       VARCHAR(24) NOT NULL,
    project_id      INT UNSIGNED NULL,
    checkout_path   VARCHAR(255) NULL,
    trunk_branch    VARCHAR(100) NOT NULL DEFAULT 'master',
    github_repo     VARCHAR(255) NULL,
    autonomy        ENUM('paired','reviewed','solo') NOT NULL DEFAULT 'paired',
    UNIQUE KEY uq_site_shortcode (shortcode),
    FOREIGN KEY (std_standard_id) REFERENCES std_standards(std_standard_id),
    FOREIGN KEY (project_id) REFERENCES projects(project_id)
);
CREATE TABLE dh_site_domains (
    dh_site_domain_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_site_id       INT UNSIGNED NOT NULL,
    dh_domain_id      INT UNSIGNED NOT NULL,
    path_prefix       VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_domain_path (dh_domain_id, path_prefix),
    FOREIGN KEY (std_site_id)  REFERENCES std_sites(std_site_id),
    FOREIGN KEY (dh_domain_id) REFERENCES dh_domains(dh_domain_id)
);

-- c_create_std_scans.sql
CREATE TABLE std_scans (
    std_scan_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scanned_at_utc  DATETIME(6) NOT NULL,
    imported_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    steps_commit    CHAR(40) NOT NULL,
    UNIQUE KEY uq_scanned_at (scanned_at_utc)
);
CREATE TABLE std_scan_sites (
    std_scan_site_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_scan_id      INT UNSIGNED NOT NULL,
    std_site_id      INT UNSIGNED NOT NULL,
    branch           VARCHAR(100) NULL,
    commit_sha       CHAR(40) NULL,
    skipped_reason   VARCHAR(255) NULL,
    UNIQUE KEY uq_scan_site (std_scan_id, std_site_id),
    FOREIGN KEY (std_scan_id) REFERENCES std_scans(std_scan_id),
    FOREIGN KEY (std_site_id) REFERENCES std_sites(std_site_id)
);
CREATE TABLE std_step_states (
    std_step_state_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_site_id       INT UNSIGNED NOT NULL,
    std_step_id       INT UNSIGNED NOT NULL,
    from_scan_id      INT UNSIGNED NOT NULL,
    until_scan_id     INT UNSIGNED NULL,
    outcome           ENUM('pass','fail','unknown') NOT NULL,
    detail            VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_state_from (std_site_id, std_step_id, from_scan_id),
    KEY idx_open (until_scan_id),
    FOREIGN KEY (std_site_id)   REFERENCES std_sites(std_site_id),
    FOREIGN KEY (std_step_id)   REFERENCES std_steps(std_step_id),
    FOREIGN KEY (from_scan_id)  REFERENCES std_scans(std_scan_id),
    FOREIGN KEY (until_scan_id) REFERENCES std_scans(std_scan_id)
);

-- d_add_issue_status_nah.sql
INSERT INTO issue_statuses (slug, label, sort_order, is_terminal, is_default)
VALUES ('nah', 'NAh', 80, 1, 0);
```

- [ ] **Step 3: Prove the SQL loads.** Start a throwaway `mysql:8.0` container, load every `db_schemas/*/*.sql` in directory order into an empty database, then confirm `SHOW TABLES LIKE 'std\_%'` lists six tables, `dh_site_domains` exists, and `SELECT slug FROM issue_statuses WHERE slug='nah'` returns one row. Keep the container and its loader script in the session scratchpad (never committed); Tasks 5, 6 and 8 reuse it.

- [ ] **Step 4: STOP for Rob's second 3NF pass.** Show Rob the SQL above as written. Do not commit or apply until he says go. Apply any change he asks for to the SQL *and* to the spec's table section.

- [ ] **Step 5: Commit** the four files: `Add the site standards tables`.

---

### Task 2: Name rules

**Files:**
- Create: `classes/Inventory/Standards/Names.php`
- Test: `Tests/Unit/StandardsNamesTest.php`

**Interfaces:**
- Produces:
  - `Names::isStepName(string $s): bool` — `^[a-z0-9][a-z0-9_]{0,63}$`
  - `Names::isShortcode(string $s): bool` — `^[a-z0-9]+(-[a-z0-9]+)*$` and at most 24 characters
  - `Names::noteTitle(string $step, string $shortcode): string` — `"{$step}.{$shortcode}"`
  - `Names::parseNoteTitle(string $title): ?array{step: string, shortcode: string}` — null unless the title is exactly a step name, one dot, a shortcode

- [ ] **Step 1: Write the failing tests**

```php
public function testStepNames(): void
{
    $this->assertTrue(Names::isStepName('rector_php_8_5_rules'));
    $this->assertFalse(Names::isStepName('_track'));
    $this->assertFalse(Names::isStepName('Has-Hyphen'));
    $this->assertFalse(Names::isStepName(''));
}

public function testShortcodes(): void
{
    $this->assertTrue(Names::isShortcode('mg'));
    $this->assertTrue(Names::isShortcode('example-games'));
    $this->assertFalse(Names::isShortcode('example--games'));
    $this->assertFalse(Names::isShortcode('-example'));
    $this->assertFalse(Names::isShortcode('example_games'));
    $this->assertFalse(Names::isShortcode(str_repeat('a', 25)));
}

public function testNoteTitleRoundTrips(): void
{
    $title = Names::noteTitle('title_of_upgrade', 'example-games');
    $this->assertSame('title_of_upgrade.example-games', $title);
    $this->assertSame(['step' => 'title_of_upgrade', 'shortcode' => 'example-games'], Names::parseNoteTitle($title));
}

public function testOrdinaryIssueTitlesAreNotNotes(): void
{
    $this->assertNull(Names::parseNoteTitle('Fix the login page'));
    $this->assertNull(Names::parseNoteTitle('v1.2 release'));
    $this->assertNull(Names::parseNoteTitle('a.b.c'));
    $this->assertNull(Names::parseNoteTitle('title_of_upgrade.example-games '));
}
```

- [ ] **Step 2: Run the Unit suite; expect these four to fail** (class not found).
- [ ] **Step 3: Implement `Names`.**
- [ ] **Step 4: Run the Unit suite; expect pass.**
- [ ] **Step 5: Commit:** `Add the name rules for steps and shortcodes`.

---

### Task 3: Enums and the checks mg answers itself

**Files:**
- Create: `StepStatus.php` (`Idea = 'idea'`, `Proven = 'proven'`), `WhoseMove.php` (`Rob = 'rob'`, `Agent = 'agent'`), `Outcome.php` (`Pass = 'pass'`, `Fail = 'fail'`, `Unknown = 'unknown'`), `Mark.php` (`Check`, `Info`, `Face`), `CheckResult.php`, `SiteFacts.php`, `HostChecks.php`
- Test: `Tests/Unit/StandardsHostChecksTest.php`

**Interfaces:**
- Produces:
  - `final readonly class CheckResult { public function __construct(public Outcome $outcome, public string $detail) }`
  - `final readonly class SiteFacts` with constructor
    `(list<array{domain: string, php_version: ?string, username: ?string, ssh_alias: ?string, login_confirmed_on: ?string}> $domains, ?string $checkoutPath, ?string $githubRepo)`
  - `HostChecks::NAMES = ['dh_has_domain', 'dh_ssh_login', 'dh_serves_php', 'repo_located']`
  - `HostChecks::answer(string $checkName, ?string $param, SiteFacts $facts): ?CheckResult` — null when `$checkName` is not in `NAMES` (it is a scanner check)
  - `HostChecks::reachable(SiteFacts $facts): bool` — `dh_has_domain` and `dh_ssh_login` both pass

Rules: `dh_has_domain` passes when `$domains` is non-empty. `dh_ssh_login` fails with detail `no domain, so no user to reach` when empty; fails with `alias found, login not proven yet: <username>` or `no ssh login recorded: <username>` for the first user lacking `login_confirmed_on`; else passes. `dh_serves_php` with param `X` fails on an empty list, fails with `<domain> serves <version>` for the first domain whose version is null or `version_compare(version, X, '<')`, else passes. `repo_located` fails with `no GitHub repo recorded` or `no checkout on Lemur 13 recorded`, else passes.

- [ ] **Step 1: Write the failing tests**

```php
private function domain(string $php, ?string $alias, ?string $confirmed): array
{
    return ['domain' => 'example.test', 'php_version' => $php, 'username' => 'exampleuser',
            'ssh_alias' => $alias, 'login_confirmed_on' => $confirmed];
}

public function testNoDomainFailsTheSshCheckInsteadOfPassingVacuously(): void
{
    $facts = new SiteFacts([], 'rob/example', 'example/example');
    $this->assertSame(Outcome::Fail, HostChecks::answer('dh_has_domain', null, $facts)?->outcome);
    $ssh = HostChecks::answer('dh_ssh_login', null, $facts);
    $this->assertSame(Outcome::Fail, $ssh?->outcome);
    $this->assertSame('no domain, so no user to reach', $ssh?->detail);
    $this->assertFalse(HostChecks::reachable($facts));
}

public function testAnAliasAloneIsNotAProvenLogin(): void
{
    $facts = new SiteFacts([$this->domain('8.5', 'example', null)], null, null);
    $ssh = HostChecks::answer('dh_ssh_login', null, $facts);
    $this->assertSame(Outcome::Fail, $ssh?->outcome);
    $this->assertSame('alias found, login not proven yet: exampleuser', $ssh?->detail);
}

public function testProvenLoginIsReachable(): void
{
    $facts = new SiteFacts([$this->domain('8.5', 'example', '2026-11-07')], null, null);
    $this->assertTrue(HostChecks::reachable($facts));
}

public function testServesPhpComparesVersionsNotStrings(): void
{
    $old = new SiteFacts([$this->domain('8.3', null, null)], null, null);
    $new = new SiteFacts([$this->domain('8.10', null, null)], null, null);
    $this->assertSame('example.test serves 8.3', HostChecks::answer('dh_serves_php', '8.5', $old)?->detail);
    $this->assertSame(Outcome::Pass, HostChecks::answer('dh_serves_php', '8.5', $new)?->outcome);
}

public function testRepoLocatedNeedsBothHalves(): void
{
    $facts = new SiteFacts([], 'rob/example', null);
    $this->assertSame('no GitHub repo recorded', HostChecks::answer('repo_located', null, $facts)?->detail);
}

public function testScannerChecksAreNotAnsweredHere(): void
{
    $this->assertNull(HostChecks::answer('phpstan_top', null, new SiteFacts([], null, null)));
}
```

- [ ] **Step 2: Run; expect fail.** **Step 3: Implement.** **Step 4: Run; expect pass.**
- [ ] **Step 5: Commit** the enums as `Add the standards enums`, then the rest as `Answer the DreamHost checks from mg's own tables`.

---

### Task 4: Parse the step files

**Files:**
- Create: `StepTree.php`, `StandardDef.php`, `TrackDef.php`, `StepDef.php`, `StandardsFormatException.php`
- Test: `Tests/Unit/StandardsStepTreeTest.php`

**Interfaces:**
- Consumes: `Names`, `StepStatus`, `WhoseMove`.
- Produces:
  - `StepTree::fromFiles(array<string, string> $files): StepTree` — keys are paths relative to `tools/site-standards/steps/`, values are file contents. Throws `StandardsFormatException` naming the file.
  - `StepTree->standards: list<StandardDef>`; `StandardDef { string $slug; string $name; list<TrackDef> $tracks }`; `TrackDef { string $slug; string $name; int $position; list<StepDef> $steps }`; `StepDef { string $slug; int $position; string $title; string $explanation; string $howTo; StepStatus $status; WhoseMove $whoseMove; string $checkName; ?string $checkParam }`.

File layout the parser accepts, and nothing else:

```
<standard>/_standard.md          frontmatter: name
<standard>/<track>/_track.md     frontmatter: name, position
<standard>/<track>/<step>.md     frontmatter: title, position, status, whose_move, check, param (optional)
```

Frontmatter is a leading block between two `---` lines of flat `key: value` pairs (a value may be wrapped in double quotes; no lists, no nesting). Do not reuse `Journal\EntryCheck`: it is pinned to PHP 8.1 for Lemur 13 and carries journal rules. In a step file the body up to a line that is exactly `## How to do it` is the explanation; everything after is the how-to (empty when the heading is absent).

Refuse: a path of the wrong depth or extension; a standard, track or step name failing `Names::isStepName` (`_standard`/`_track` excepted); a missing `_standard.md` or `_track.md`; a missing or unknown frontmatter key; `position` not a positive integer; `status` or `whose_move` outside the enums; an empty `title` or `check`; **two steps with the same name anywhere in one standard**.

- [ ] **Step 1: Write the failing tests**

```php
private const array GOOD = [
    'template/_standard.md' => "---\nname: Template sites\n---\n",
    'template/rector/_track.md' => "---\nname: Rector\nposition: 30\n---\n",
    'template/rector/rector_php_8_5_rules.md' =>
        "---\ntitle: \"Rector's 8.5 rules, one per commit\"\nposition: 10\nstatus: proven\n"
        . "whose_move: agent\ncheck: rector_level\nparam: 8.5\n---\nWhy it matters.\n\n## How to do it\n\n1. Do this.\n",
];

public function testParsesAStep(): void
{
    $step = StepTree::fromFiles(self::GOOD)->standards[0]->tracks[0]->steps[0];
    $this->assertSame('rector_php_8_5_rules', $step->slug);
    $this->assertSame("Rector's 8.5 rules, one per commit", $step->title);
    $this->assertSame(StepStatus::Proven, $step->status);
    $this->assertSame(WhoseMove::Agent, $step->whoseMove);
    $this->assertSame('rector_level', $step->checkName);
    $this->assertSame('8.5', $step->checkParam);
    $this->assertSame('Why it matters.', trim($step->explanation));
    $this->assertSame('1. Do this.', trim($step->howTo));
}

public function testAStepWithNoHowToHeadingIsAllExplanation(): void
{
    $files = self::GOOD;
    $files['template/rector/rector_php_8_5_rules.md'] = str_replace("\n## How to do it\n\n1. Do this.\n", '', $files['template/rector/rector_php_8_5_rules.md']);
    $this->assertSame('', StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->howTo);
}

public function testRefusesTheSameStepNameTwiceInOneStandard(): void
{
    $files = self::GOOD + [
        'template/tooling/_track.md' => "---\nname: Tooling\nposition: 20\n---\n",
        'template/tooling/rector_php_8_5_rules.md' => self::GOOD['template/rector/rector_php_8_5_rules.md'],
    ];
    $this->expectException(StandardsFormatException::class);
    $this->expectExceptionMessageMatches('/rector_php_8_5_rules/');
    StepTree::fromFiles($files);
}

public function testRefusesATrackWithNoTrackFile(): void
{
    $files = self::GOOD;
    unset($files['template/rector/_track.md']);
    $this->expectException(StandardsFormatException::class);
    StepTree::fromFiles($files);
}

public function testRefusesAnUnknownStatus(): void
{
    $files = self::GOOD;
    $files['template/rector/rector_php_8_5_rules.md'] = str_replace('status: proven', 'status: done', $files['template/rector/rector_php_8_5_rules.md']);
    $this->expectException(StandardsFormatException::class);
    StepTree::fromFiles($files);
}

public function testARemovedStepIsSimplyAbsentFromTheTree(): void
{
    $files = self::GOOD;
    unset($files['template/rector/rector_php_8_5_rules.md']);
    $this->assertSame([], StepTree::fromFiles($files)->standards[0]->tracks[0]->steps);
}
```

- [ ] **Step 2: Run; expect fail.** **Step 3: Implement.** **Step 4: Run; expect pass.**
- [ ] **Step 5: Commit:** `Parse the step files into a tree`.

---

### Task 5: The scan document and its importer

**Files:**
- Create: `ScanPayload.php`, `ScanRefusedException.php`, `StandardsImporter.php`, `tools/import_site_standards.php`
- Test: `Tests/Unit/StandardsScanPayloadTest.php`

**Interfaces:**
- Consumes: `StepTree`, `Outcome`, `Names`, `\Inventory\SpanPlan`, `\Inventory\OpenSpan`, `\Inventory\SweptSpan`.
- Produces:
  - The JSON document (the scanner in Task 9 writes exactly this):

```json
{"scanned_at_utc": "2026-11-07 01:20:00",
 "steps_commit": "<40 hex: mg master>",
 "files": {"template/_standard.md": "…", "template/rector/_track.md": "…"},
 "sites": [
   {"shortcode": "mg", "branch": "master", "commit": "<40 hex>", "skipped_reason": null,
    "results": [{"step": "phpstan_level_10_no_baseline", "outcome": "pass", "detail": "level 10, run clean"}]},
   {"shortcode": "example-games", "branch": "example-topic", "commit": null,
    "skipped_reason": "on branch example-topic", "results": []}
 ]}
```

  - `ScanPayload::fromArray(array<mixed> $data): ScanPayload` — throws `StandardsFormatException`. Properties: `string $scannedAtUtc`, `string $stepsCommit`, `StepTree $tree`, `list<array{shortcode: string, branch: ?string, commit: ?string, skippedReason: ?string, results: list<array{step: string, outcome: Outcome, detail: string}>}> $sites`.
  - `StandardsImporter::__construct(\PDO $pdo)`; `import(ScanPayload $payload, bool $dryRun = false): array<string, int>` — a summary keyed `steps_new`, `steps_updated`, `steps_retired`, `sites_scanned`, `sites_skipped`, `states_opened`, `states_closed`, `states_unchanged`. Throws `ScanRefusedException`.

`ScanPayload` refuses: a missing key; `scanned_at_utc` not `Y-m-d H:i:s`; `steps_commit` or a non-null `commit` not 40 hex; a shortcode failing `Names::isShortcode`; a site with a `skipped_reason` **and** results; a site with neither; a `detail` longer than 255 characters (truncate nothing, refuse); an unknown outcome; anything `StepTree::fromFiles` refuses.

`StandardsImporter`, in one transaction (rolled back on `--dry-run`, as `SweepImporter::import` does):
1. Refuse when `scanned_at_utc` is not later than the latest `std_scans.scanned_at_utc`.
2. Upsert standards and tracks by slug. Upsert steps matched by **(standard, step slug)**, so a step moved to another track keeps its id; set `retired_on = NULL` on any step present. Set `retired_on` to the scan's date on every unretired step of an imported standard that the tree no longer has, and close its open states.
3. Insert the `std_scans` row.
4. For each site: refuse an unknown shortcode. Insert `std_scan_sites`. **If the site was skipped, touch none of its states.** Otherwise refuse a result naming a step the site's standard does not have, then sync that site's states with `SpanPlan::diff` (key: step id; attrs: `outcome`, `detail`), closing with `until_scan_id` and opening with `from_scan_id`.

`tools/import_site_standards.php` mirrors `tools/import_dh_sweep.php`: CLI only, same autoloader lines, JSON from STDIN, `--dry-run`, `REFUSED: <reason>` on stderr with exit 1, then one summary line per key.

- [ ] **Step 1: Write the failing tests** (`ScanPayload` only; the importer has no committed database test, per the spec)

```php
public function testAcceptsAScannedAndASkippedSite(): void
{
    $payload = ScanPayload::fromArray($this->good());
    $this->assertSame('mg', $payload->sites[0]['shortcode']);
    $this->assertSame(Outcome::Pass, $payload->sites[0]['results'][0]['outcome']);
    $this->assertSame('on branch example-topic', $payload->sites[1]['skippedReason']);
    $this->assertSame([], $payload->sites[1]['results']);
}

public function testRefusesASkippedSiteThatAlsoCarriesResults(): void
{
    $data = $this->good();
    $data['sites'][1]['results'] = $data['sites'][0]['results'];
    $this->expectException(StandardsFormatException::class);
    ScanPayload::fromArray($data);
}

public function testRefusesAnOutcomeItDoesNotKnow(): void { /* 'outcome' => 'maybe' */ }
public function testRefusesADetailOver255Characters(): void { /* str_repeat('x', 256) */ }
public function testRefusesAShortcodeWithBadCharacters(): void { /* 'Example_Games' */ }
public function testAPayloadWithNoSitesIsFine(): void { /* 'sites' => [] loads steps only */ }
```

`good()` returns the JSON example above as an array, with `files` taken from Task 4's `GOOD` plus a `phpstan` track holding `phpstan_level_10_no_baseline`. Write each one-line test in full; the comment says which field to change.

- [ ] **Step 2: Run; expect fail.** **Step 3: Implement `ScanPayload`.** **Step 4: Run; expect pass.** **Step 5: Commit:** `Check the scan document before importing it`.
- [ ] **Step 6: Implement `StandardsImporter` and the CLI tool.** Commit each: `Import a scan in one transaction`, `Add the scan import command`.
- [ ] **Step 7: Verify by hand in the Task 1 container.** Pipe three documents in order and check with SQL after each:
  1. no sites, `GOOD` files → `steps_new` equals the step count; a second identical run with a later time reports `steps_new 0`.
  2. after inserting a site row for `mg` by hand: one pass result → one open state.
  3. the same site **skipped** → `states_closed 0` and the state from (2) still has `until_scan_id IS NULL`. Then a document without the step's file → the step has `retired_on` set and its state is closed.
  Also confirm `--dry-run` leaves `std_scans` unchanged.

---

### Task 6: Hand-kept site facts

**Files:**
- Create: `SiteStore.php`, `tools/site_standards.php`, `tools/site-standards/site.sh`

**Interfaces:**
- Consumes: `Names`.
- Produces:
  - `SiteStore::__construct(\PDO $pdo)`
  - `add(string $shortcode, string $standardSlug, ?int $projectId): int`
  - `set(string $shortcode, array{project_id?: ?int, checkout_path?: ?string, trunk_branch?: string, github_repo?: ?string, autonomy?: string} $changes): void`
  - `link(string $shortcode, string $domain, string $pathPrefix = ''): void`, `unlink(string $shortcode, string $domain, string $pathPrefix = ''): void`
  - `forScanner(): list<array{shortcode: string, standard: string, checkout_path: ?string, trunk_branch: string}>`
  - All throw `\InvalidArgumentException` with a plain sentence for: a bad or taken shortcode, an unknown standard, project, domain or autonomy value, a `checkout_path` that is absolute or contains `..`, a `(domain, path)` already linked.
- CLI: `php tools/site_standards.php sites` prints `forScanner()` as JSON; `site-add --shortcode= --standard= [--project=]`; `site-set --shortcode= [--project=] [--checkout=] [--trunk=] [--github=] [--autonomy=]`; `site-link --shortcode= --domain= [--path=]`; `site-unlink` likewise. Errors go to stderr, exit 1.
- `tools/site-standards/site.sh "$@"` runs the CLI on the server: `ssh mg /usr/local/php85/bin/php '~/mg.robnugen.com/tools/site_standards.php'` with each argument passed through `printf %q`.

A standard exists only after a first import, so `site-add` before any import fails with `No such standard: template. Import the step files first.`

- [ ] **Step 1: Implement `SiteStore`.** Commit: `Keep the hand-entered facts about a site`.
- [ ] **Step 2: Implement the CLI and wrapper.** Commit: `Add the site command for the standards page`.
- [ ] **Step 3: Verify by hand in the container:** add `mg` on `template`, set a checkout path, link a domain, run `sites` and read the JSON; then confirm each refusal above prints its sentence and exits 1.

---

### Task 7: The mark rule and one site's report

**Files:**
- Create: `SiteStanding.php`, `Standing.php`, `StepLine.php`, `NoteIssue.php`, `SiteReport.php`
- Test: `Tests/Unit/StandardsSiteStandingTest.php`, `Tests/Unit/StandardsSiteReportTest.php`

**Interfaces:**
- Consumes: `Mark`, `Outcome`, `StepStatus`, `WhoseMove`, `HostChecks`, `SiteFacts`, `CheckResult`, `Names`.
- Produces:
  - `SiteStanding::of(bool $reachable, list<StepLine> $provenLines): Standing`; `Standing { Mark $mark; string $summary }`
  - `StepLine { string $slug; string $trackName; string $title; WhoseMove $whoseMove; bool $done; string $detail; bool $nah; list<NoteIssue> $issues; string $explanation; string $howTo }`
  - `NoteIssue { int $issueId; string $title; string $statusSlug; string $statusLabel }`
  - `SiteReport::build(string $shortcode, SiteFacts $facts, list<array<mixed>> $stepRows, array<string, array{outcome: string, detail: string}> $openStates, list<NoteIssue> $projectIssues, list<string> $shortcodesInProject): SiteReport`
  - `SiteReport { Standing $standing; list<StepLine> $lines; list<NoteIssue> $strays }`

`SiteStanding` rule, in this order: not reachable → `Face`, `can't reach it yet`. Every line done → `Check`, `up to date`. Any open line whose move is Rob's → `Info`, `waiting on you`. Otherwise `Info`, `1 step to go` or `N steps to go`.

`SiteReport::build` takes only **proven, unretired** step rows (columns as in `std_steps` plus `track_name`), already in track then step order. Per step: the result is `HostChecks::answer()` when it answers, else the open state (`pass` → done, with its detail), else not done with detail `not checked yet`. Its issues are every project issue whose title is exactly `Names::noteTitle(slug, shortcode)`; if any has status slug `nah`, the line is done and `nah` is true. `strays` are project issues whose title parses as a note but whose shortcode is not in `$shortcodesInProject`, or is this site's with a step name no row has.

- [ ] **Step 1: Write the failing tests**

```php
// StandardsSiteStandingTest
public function testUnreachableComesFirstEvenWhenEverythingElseIsDone(): void
{
    $s = SiteStanding::of(false, [$this->line(done: true)]);
    $this->assertSame(Mark::Face, $s->mark);
    $this->assertSame("can't reach it yet", $s->summary);
}
public function testAllDoneIsTheCheckmark(): void          { /* Check, 'up to date' */ }
public function testNoProvenStepsIsUpToDate(): void         { /* of(true, []) → Check */ }
public function testRobsOpenMoveWins(): void                { /* one agent open + one rob open → Info, 'waiting on you' */ }
public function testCountsOpenSteps(): void                 { /* 1 open → '1 step to go'; 3 open → '3 steps to go' */ }

// StandardsSiteReportTest
public function testAScannerStepNeverScannedReadsNotCheckedYet(): void
{
    $report = SiteReport::build('mg', $this->reachableFacts(), [$this->step('phpstan_level_10_no_baseline', 'phpstan_top')], [], [], ['mg']);
    $this->assertFalse($report->lines[0]->done);
    $this->assertSame('not checked yet', $report->lines[0]->detail);
    $this->assertSame(Mark::Info, $report->standing->mark);
}
public function testAPassingStateTicksTheStep(): void       { /* openStates[slug] = pass → done, Check */ }
public function testAnIssueMarkedDoneDoesNotTickTheStep(): void { /* issue status 'done', no state → not done, issue listed */ }
public function testNahCountsAsDone(): void                 { /* issue status 'nah' → done, nah true, Check */ }
public function testTwoIssuesWithTheSameTitleBothShow(): void { /* two NoteIssue same title, one 'open' one 'nah' → count 2, done */ }
public function testANoteForAnotherSiteIsAStray(): void     { /* 'x.example-games' in mg's project, shortcodesInProject ['mg'] → strays count 1 */ }
public function testANoteForARenamedStepIsAStray(): void    { /* 'old_name.mg' with no such step row → stray, no exception */ }
public function testOrdinaryIssuesAreIgnored(): void        { /* 'Fix the login page' → no stray, no line issue */ }
```

Write every test in full, in the style of the first of each group.

- [ ] **Step 2: Run; expect fail.** **Step 3: Implement.** **Step 4: Run; expect pass.**
- [ ] **Step 5: Commit** `Decide a site's mark from its steps`, then `Assemble one site's report from plain rows`.

---

### Task 8: The page, the nav link and the domain panel

**Files:**
- Create: `Board.php`, `wwwroot/admin/dh/standards.php`, `templates/admin/dh/standards.tpl.php`
- Modify: `templates/admin/dh/_nav.tpl.php` (add `standards` after `overview`), `wwwroot/admin/dh/domain.php`, `templates/admin/dh/domain.tpl.php`, `wwwroot/css/styles.css`

**Interfaces:**
- Consumes: `SiteReport`, `SiteFacts`, `NoteIssue`, `\Utilities::renderMarkdown`, `\Inventory\AdminPage`.
- Produces:
  - `Board::__construct(\PDO $pdo)`
  - `Board::standards(): list<array{slug: string, name: string, proven: int, sites: list<array{shortcode: string, project_id: ?int, autonomy: string, last_checked_utc: ?string, skipped_reason: ?string, report: SiteReport}>, ideas: list<array{track: string, title: string}>}>`
  - `Board::sitesOnDomain(int $dhDomainId): list<array{shortcode: string, path_prefix: string, report: SiteReport}>`

`Board` queries: sites with their standard; each site's domains with the **current** `dh_domain_states` row and its `dh_users` row (for `SiteFacts`); proven and idea steps that are not retired; open `std_step_states`; per site the latest unskipped `std_scan_sites` time and the latest row's `skipped_reason`; issues in the site's project joined to `issue_statuses`, limited to titles containing a dot. A site appears whether or not it has domains.

Page (`standards.php` follows `index.php` line for line: prepend include, `@var` block, `AdminPage::guard`, `AdminPage::render` with title `Site standards`):
- Per standard: heading, `N proven steps`, then one `<details id="site-<shortcode>">` per site. `<summary>` holds the mark, the shortcode and the summary. Inside: lines grouped under their track's name, in the order `Board` returns them; a done line shows `✓`, an open one `·`, its title, whose move (`your move` / `an agent can do this`), its detail, any issues as `#<id> <status label>` linking to `/issues/view.php?issue_id=<id>`, and for an open line the explanation rendered with `\Utilities::renderMarkdown` plus a nested `<details>` for the how-to. A NAh line reads `NAh` with its issue link. Below the lines: the site's autonomy (`paired`, `reviewed` or `solo`), `Trunk last checked <time> UTC`, the skip reason if any, and the strays under `Notes that match no step here`.
- After the sites: `Ideas not yet tried on any site`, by track.
- With no standards imported: one sentence, `No step files imported yet.`, and nothing else.
- Every value through `AdminPage::h` except the two Markdown renders.

Marks in CSS: classes `StdMark StdMark--check` (colour `var(--success-color)` if `styles.css` defines one, else add a green that reads in both themes), `StdMark--info` and `StdMark--face` (inherit colour). No red, no warning glyph anywhere in the template.

Domain page: `domain.php` passes `'standards_sites' => (new \Inventory\Standards\Board($mla_database))->sitesOnDomain($domain_id)` (the template already has a `$sites`; do not reuse the name). The template gains, above "Website history", a `PagePanel` titled `Sites in this domain`, one line per site: mark, shortcode, path when not empty, summary, link to `/admin/dh/standards.php#site-<shortcode>`. When the list is empty the panel is not printed.

- [ ] **Step 1: Implement `Board`.** Commit: `Read the standards board from the database`.
- [ ] **Step 2: Add the page, template and nav link.** Commit the entry script and nav as `Add the site standards page`, the template and CSS as `Show each site's steps on the standards page`.
- [ ] **Step 3: Add the domain panel.** Commit: `Show a domain's sites on its page`.
- [ ] **Step 4: Verify by hand.** Serve the checkout against the Task 1 container (`php -S` inside the php8.5 runner on the container's network, with a scratch `Config.php`), log in as user 1, and confirm: the empty state; after Task 5's documents and a site row, the row's mark, summary and lines; a NAh issue ticking a step; a stray listed; `standards.php` returning 403 for a non-owner. Grep the two templates for `⚠`, `warning`, `danger`, `red`: expect no match.

---

### Task 9: Step files, checks and the scanner

**Files:**
- Create: `tools/site-standards/steps/template/_standard.md` and one directory per track with `_track.md`:

| Track dir (position) | Step file | status | whose_move | check | param |
|---|---|---|---|---|---|
| `groundwork` (10) | `has_a_domain.md` | proven | rob | `dh_has_domain` | |
| | `ssh_login_works.md` | proven | rob | `dh_ssh_login` | |
| | `repo_located.md` | proven | rob | `repo_located` | |
| | `checks_in_place.md` | proven | agent | `checks_in_place` | |
| `tooling` (20) | `tooling_on_php_8_5.md` | proven | agent | `tooling_on_php` | `8.5` |
| `php_version` (30) | `serves_php_8_5.md` | proven | rob | `dh_serves_php` | `8.5` |
| `phpstan` (40) | `phpstan_level_10_no_baseline.md` | proven | agent | `phpstan_top` | |
| `rector` (50) | `rector_php_8_5_rules.md` | proven | agent | `rector_level` | `8.5` |
| | `rector_type_declarations.md` | proven | agent | `rector_types` | |
| `phpcs` (60), `mlaphp` (70), `security` (80), `features` (90) | `mlaphp/remove_globals_from_prepend.md` only | idea | agent | `mlaphp_no_prepend_globals` | |

- Create: `tools/site-standards/lib.sh`, `scan.sh`, and `checks/{checks_in_place,tooling_on_php,phpstan_top,rector_level,rector_types}.sh`
- Modify: `CLAUDE.md` (new section, Step 6)

**Interfaces:**
- Consumes: the JSON document of Task 5; `site.sh sites` of Task 6; `~/work/rob/standards-mcp/run-phpstan.sh`, `run-rector.sh`, `runner-image.sh`.
- Produces: `checks/<name>.sh REPO [PARAM]` → exit 0 pass, 1 not yet, 2 could not tell; exactly one line on stdout, at most 255 characters, no site names.

Each step file's explanation says, for a reader of the page, what the step is and why it matters, in two to five sentences. The how-to for the Rector steps is taken from the fork that proved them: one rule per commit with `run-rector.sh --only=`, run the Unit gate and phpcs between rules, run on the image for the target PHP because Rector takes its target from the PHP it runs on, lint with the server's own `php` of the currently served version before deploying, and leave a callback on request or third-party data untyped under `skip`. Name no site. The idea step's explanation says what it would mean; its how-to is empty.

Checks:
- `checks_in_place`: `phpcs.xml`, `phpstan.neon` (or `.dist`), `Tests/Unit/` and `.githooks/pre-commit` exist and `git -C REPO config core.hooksPath` is `.githooks`. Detail names the first thing missing.
- `tooling_on_php X`: `.githooks/pre-commit` names `runner:phpX`; `composer.json`'s `php` requirement mentions `X`; `runner_image REPO` (sourced from standards-mcp) ends `:phpX`.
- `phpstan_top`: `phpstan.neon` has `level: 10` or `level: max` and no `includes:` of a baseline (else exit 1); then `run-phpstan.sh REPO`: exit 0 → pass, `GATE SKIP` in its output → exit 2, anything else → exit 1.
- `rector_level X`: no `rector.php`, or no `UP_TO_PHP_<X without the dot>` in it → exit 1. Then the dry run from `lib.sh`: 0 → pass; 2 → exit 1 `Rector would still change files`; `GATE SKIP` or any other code → exit 2.
- `rector_types`: as `rector_level` but looking for `TYPE_DECLARATION`.
- `lib.sh` provides `rector_dry_run REPO`, which runs `run-rector.sh REPO` once per repo per scan and caches the exit code and output under `$SCAN_TMP`.

`scan.sh [shortcode…]`:
1. `MG_REPO` = this script's repo; `STANDARDS_MCP` defaults to `~/work/rob/standards-mcp`; `SCAN_TMP=$(mktemp -d)`, removed on exit.
2. Read step files **from `master`**, never the working tree: `git -C "$MG_REPO" ls-tree -r --name-only "$STEPS_REF" -- tools/site-standards/steps` and `git show "$STEPS_REF":<path>`; `steps_commit` is `git rev-parse "$STEPS_REF"`. `STEPS_REF` defaults to `master`. Setting it to anything else prints `Reading steps from <ref>, not master` on stderr; it exists for the first look in Task 10 and is not for routine use.
3. Site list from `site.sh sites`, narrowed to the arguments when given.
4. Per site: no `checkout_path` or no such directory → skipped, `no local checkout`. `git symbolic-ref --short HEAD` differing from `trunk_branch` → skipped, `on branch <name>`. `git status --porcelain` not empty → skipped, `uncommitted changes`. Otherwise, for every step file of the site's standard: read `check` and `param` from its frontmatter (a few lines of `python3 -I`; the importer, not the scanner, is the judge of a step file). **If `checks/<check>.sh` exists in this working tree, run it** as `checks/<check>.sh "$repo" "$param"`; exit 0/1/2 map to `pass`/`fail`/`unknown`, any other code to `unknown`. If no such script exists, report nothing for that step: it is either a check mg answers itself or an idea with no check yet, and the page says `not checked yet` when it is neither. The scanner therefore holds no list of mg's own check names.
5. Build the document with `python3 -I` (as `tools/import-dh-sweep.sh` does) and pipe it to `ssh mg "/usr/local/php85/bin/php ~/mg.robnugen.com/tools/import_site_standards.php $flag"`. `--dry-run` anywhere in the arguments is passed through.

- [ ] **Step 1: Write the step files.** Verify with a throwaway PHP one-liner in the runner that `StepTree::fromFiles` accepts the directory. Commit: `Write up the template standard's first steps`.
- [ ] **Step 2: Write `lib.sh` and the five checks.** Run each against this repo and read the line: `checks_in_place` and `phpstan_top` pass; `tooling_on_php … 8.5` passes; `rector_level … 8.5` exits 1 (`no rector.php`). Run each against an empty temp directory: exit 1 or 2 with a sentence, never a shell error. Commit: `Add the scanner's checks`.
- [ ] **Step 3: Write `scan.sh`.** Run `bash -n` and `shellcheck` if installed. Commit: `Scan each site's checkout and push the results`.
- [ ] **Step 4: Dry-run against the Task 1 container** by pointing the final `ssh` at it through an environment override used only for this test (`SCAN_IMPORT_CMD`), with this repo on `dh-standards`: confirm steps still come from `master` and that `mg` is reported skipped with `on branch dh-standards`.
- [ ] **Step 5: Run all three gates** (`run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh`); all must pass.
- [ ] **Step 6: Document it in `CLAUDE.md`,** a section "Site standards (which sites are behind)": where step files live and that only `master`'s count; the note-title rule and NAh; that the scanner checks only a clean trunk; the three commands (`scan.sh`, `site.sh`, `--dry-run`); that no real site name goes in the repo. Commit: `Note how the standards page is wired`.

---

### Task 10: Ship it and look at it

Each step here changes production or needs Rob. Ask before each one; an earlier yes does not cover the next.

- [ ] **Step 1: Decide where the first look reads its steps from.** The step files are on `dh-standards`, and `master` gets them only when the bubble closes. Ask Rob: scan with `STEPS_REF=dh-standards` for the first look (the one sanctioned use), or close the bubble first. While this repo is on `dh-standards`, `mg` itself is skipped as `on branch dh-standards`; its scanner steps read "not checked yet" until the first scan from `master`.
- [ ] **Step 2: Deploy** with the `deploy` skill (push, then pull on the server). Confirm `v.0.59.0` on a page footer.
- [ ] **Step 3: Apply migration `45_site_standards`** on production through `/admin/migrate_tables.php`, the way 39 was applied. Confirm the eight tables and the `nah` status with `ssh mg "mysql mgrnc -e …"`.
- [ ] **Step 4: First import, steps only:** `tools/site-standards/scan.sh --dry-run`, read the summary, then without the flag.
- [ ] **Step 5: Enter the two sites** with `site.sh`: `mg` and the second site Rob named, each with its mg project, standard `template`, its checkout path, GitHub repo and domains (the second site has two). Prove each site's ssh login with the `recover-dh-user` skill where an alias already works, so the face is left for sites that cannot be reached.
- [ ] **Step 6: Scan:** `scan.sh --dry-run`, then for real. A site whose checkout is off its trunk will be skipped; that is correct and the page will say so.
- [ ] **Step 7: Rob looks at `/admin/dh/standards.php`** and at both domains' pages. Collect what he wants changed; that list is the next piece of work, not part of this plan.
- [ ] **Step 8: Close the bubble** with the `close-bubble` skill once Rob gives the closing words.
