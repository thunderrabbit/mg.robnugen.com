# Scan Follows The Test Domain Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A site with a test domain is scanned when its checkout sits at the commit the test domain runs, so Rob can move it on the board by deploying to the test domain only.

**Architecture:** `scan.py` gains a third way for a checkout to qualify and sends the test domain's commit as `test_commit`. On mg, `ScanPayload` parses it, a new pure rule class decides whether an off-server scan is allowed, the importer stores it in a new column, and the board words it. The skill and `CLAUDE.md` are updated last.

**Tech Stack:** Python 3 `unittest` (scanner, on Lemur 13), PHP 8.5 with the Codeception Unit suite, phpcs and PHPStan in Docker (mg), one MySQL migration.

**Spec:** `docs/superpowers/specs/2026-11-13-scan-follows-test-domain-design.md`

## Global Constraints

- Branch `scan-follows-staging`, already open with BEGIN (0.84.0) and the design. Never checkout or merge into master. Do not push. Do not close the bubble; Rob says when.
- A site with an upgrade branch on mg never uses the test-domain rule.
- A step must never tick for work that runs nowhere: the checkout's commit must equal the commit the test domain answered with, and the server's commit must be an ancestor of it.
- No site's deploy script changes. No marker branches. `js_hotfixes_ported` and `suite_passes_on_php` are not touched.
- This repo is public: tests, comments and docs use `demo`, `shop`, `shop-test.example` or `mg`, never a real shortcode or domain.
- The board keeps three marks and no warning styling.
- Commits: subject 50 chars or fewer, imperative, body says why, multiple `-m` flags, never a heredoc, last `-m` is `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`. A template text change never rides with a database-layer change.
- Before calling any PHP task done: `~/work/rob/standards-mcp/run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh`, each with `~/work/rob/mg.robnugen.com`. The pre-commit hook is the stricter judge; fix what it reports, never `--no-verify`.
- Python tests: `python3 -I -m unittest discover -s tools/site-standards/tests`.
- Migration files are named `[a-z]+_*.sql` under `db_schemas/NN_name/`; Rob applies 02+ by hand.

## Review Focus

1. The test domain's ssh fails or is slow: the site is skipped with a reason that says the test domain could not be asked; the scan of other sites goes on. Test in Task 1.
2. Production took a fix the test line lacks (server commit not an ancestor of the checkout): skipped, naming both commits. Test in Task 1.
3. A site with both an upgrade branch and a test domain, checkout at the test domain's commit but not on the upgrade branch: skipped exactly as today, and the test domain is never asked. Test in Task 1; importer refusal in Task 3.
4. A scan document from a scanner older than this change (no `test_commit` key): imports as before. Test in Task 2.
5. The new mg code is deployed before migration 55 is applied: the board still renders. Test by reading `ss.*` with `array_key_exists`, as `php_target` does (Task 4). The importer's INSERT does need the column; Task 3 says so in the migration's comment and Task 5 in the hand-back.

---

### Task 1: Scanner reads a checkout at the test domain's commit

**Files:**
- Modify: `tools/site-standards/scan.py` (`scan_site`, around lines 284 to 305; module docstring)
- Create: `tools/site-standards/tests/test_scan_test_domain.py`

**Interfaces:**
- Produces: every site entry `scan_site` returns, scanned or skipped, has `"test_commit"`: a 40-character hash or `None`.

- [ ] **Step 1: Write the failing tests**

Build the fixture as `ScanTargetTest.setUp` in `tests/test_scan_target.py` does, with these differences: the repo gets two commits (`first`, then `second` on a branch `work`); the fake ssh answers by its first argument, so the test controls each host. Write the fake as a script that prints the contents of `<root>/answers/<$1>` and exits 0, or exits 255 when that file is missing. The site is

```python
{"shortcode": "demo", "checkout_path": "demo", "standard": "std",
 "server": {"ssh_alias": "prod", "directory": "demo"},
 "test": {"domain": "shop-test.example", "ssh_alias": "stage", "directory": "demo-test"}}
```

with `first` = the commit of `first`, `second` = HEAD. Check what `server_facts.head_and_branch` expects on stdout before writing the answer files; the existing fake prints only `git rev-parse HEAD`.

```python
def test_checkout_at_the_test_domains_commit_is_scanned(self):
    self.answer("prod", self.first); self.answer("stage", self.second)
    result = self.scan()
    self.assertIsNone(result["skipped_reason"])
    self.assertEqual(result["commit"], self.second)
    self.assertEqual(result["server_commit"], self.first)
    self.assertEqual(result["test_commit"], self.second)
    self.assertEqual(result["results"][0]["outcome"], "pass")

def test_test_domain_on_another_commit_skips(self):
    self.answer("prod", self.first); self.answer("stage", self.first)
    # checkout is at second
    result = self.scan()
    self.assertIn("its test domain runs", result["skipped_reason"])
    self.assertEqual(result["test_commit"], self.first)
    self.assertEqual(result["results"], [])

def test_test_domain_that_cannot_be_asked_skips(self):
    self.answer("prod", self.first)          # no answer file for stage
    result = self.scan()
    self.assertIn("its test domain could not be asked", result["skipped_reason"])
    self.assertIsNone(result["test_commit"])

def test_server_commit_outside_the_checkout_skips(self):
    # a third commit on a side branch that `work` does not contain
    self.answer("prod", self.side); self.answer("stage", self.second)
    result = self.scan()
    self.assertIn("does not include", result["skipped_reason"])

def test_upgrade_branch_site_never_asks_the_test_domain(self):
    self.site["upgrade_branch"] = "green"     # checkout is on `work`
    self.answer("prod", self.first); self.answer("stage", self.second)
    result = self.scan()
    self.assertIn("not on green", result["skipped_reason"])
    self.assertIsNone(result["test_commit"])
    self.assertNotIn("stage", self.asked())   # the fake logs each $1 it was called with

def test_checkout_at_the_servers_commit_never_asks_the_test_domain(self):
    self.answer("prod", self.second); self.answer("stage", self.first)
    result = self.scan()
    self.assertIsNone(result["skipped_reason"])
    self.assertIsNone(result["test_commit"])
    self.assertNotIn("stage", self.asked())

def test_site_without_a_test_domain_skips_as_before(self):
    self.site["test"] = None
    self.answer("prod", self.first)
    result = self.scan()
    self.assertIn("the checkout is at", result["skipped_reason"])
```

- [ ] **Step 2: Run them and see them fail**

Run: `python3 -I -m unittest discover -s tools/site-standards/tests -p 'test_scan_test_domain.py'`
Expected: failures on `test_commit` (KeyError) and on the scanned case (skipped).

- [ ] **Step 3: Implement in `scan_site`**

Add `test_commit = None` beside `server_commit`, and put `"test_commit": test_commit` in both the `skipped()` entry and the scanned entry. In the `if commit != server_commit:` block, keep the upgrade-branch arm first and unchanged. A site with `upgrade_branch` set falls to the existing skips. Otherwise, when `site.get("test")` has an `ssh_alias` and a `directory`:

1. `test_commit, _, problem = ask_server(site["test"])`. On a problem: skip with `f"its server runs {on}; its test domain could not be asked: {problem}"`.
2. `commit != test_commit`: skip with `f"its server runs {on} and its test domain runs {test_commit[:7]}; the checkout is at {commit[:7]}"`.
3. `git merge-base --is-ancestor server_commit commit` is not 0 (this includes a server commit the checkout lacks): skip with `f"its server runs {on}, which the test domain's {test_commit[:7]} does not include"`.
4. Otherwise `say(f"  {shortcode}: checking what {site['test'].get('domain') or 'its test domain'} runs ({commit[:7]}); its server runs {on}")` and go on to the clean check and the checks.

Do not add anything to `check_environment`: a new `SITE_*` fact would change every memo key.

Add one bullet to the module docstring after the upgrade-branch one, saying the same in two sentences.

- [ ] **Step 4: Run the whole Python suite**

Run: `python3 -I -m unittest discover -s tools/site-standards/tests`
Expected: OK, no failures, including the existing `test_scan_target.py` and `test_scan_memo.py`.

- [ ] **Step 5: Commit**

```bash
git add tools/site-standards/scan.py tools/site-standards/tests/test_scan_test_domain.py
git commit -m "Scanner: read a checkout the test domain runs" -m "A site that deploys to its test domain first was always skipped, so it never moved on the board. The server's commit must still be inside the checkout." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 2: The scan document carries `test_commit`

**Files:**
- Modify: `classes/Inventory/Standards/ScanPayload.php` (`site()`, the class docblock's example and its return shape)
- Modify: `tools/import_site_standards.php` (only if its header describes the document's fields)
- Test: `Tests/Unit/StandardsScanPayloadTest.php`

**Interfaces:**
- Produces: each parsed site array has `'testCommit' => ?string`, beside `'serverCommit'`.

- [ ] **Step 1: Write the failing tests**, in the style of the file's `server_commit` tests:

```php
public function testTestCommitIsKept(): void            // 'test_commit' => str_repeat('b', 40)  → $site['testCommit'] same string
public function testTestCommitMayBeLeftOut(): void      // key absent → $site['testCommit'] === null
public function testTestCommitMayBeNull(): void         // null → null
public function testShortTestCommitIsRefused(): void    // 'abc1234' → StandardsFormatException, message contains 'test_commit must be a full 40-character commit hash'
```

- [ ] **Step 2: Run and see them fail**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`
Expected: the four new tests fail.

- [ ] **Step 3: Implement** in `ScanPayload::site()`, exactly as `server_commit` is read and checked; add `testCommit` to the returned array and to every `@return`/`@param` array shape that lists `serverCommit` (`ScanPayload`, `StandardsImporter::importSite`).

- [ ] **Step 4: Run the three gates.** Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add classes/Inventory/Standards/ScanPayload.php classes/Inventory/Standards/StandardsImporter.php Tests/Unit/StandardsScanPayloadTest.php
git commit -m "Scan document: take the test domain's commit" -m "The scanner now says which commit a site's test domain runs. Optional, so a document from an older scanner still imports." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 3: The importer accepts a test-domain scan and stores it

**Files:**
- Create: `classes/Inventory/Standards/OffServerScan.php`
- Create: `Tests/Unit/StandardsOffServerScanTest.php`
- Create: `db_schemas/55_standards_test_commit/a_add_std_scan_sites_test_commit.sql`
- Modify: `classes/Inventory/Standards/StandardsImporter.php` (`importSite`)

**Interfaces:**
- Consumes: `$site['testCommit']` from Task 2.
- Produces: `OffServerScan::refusal(string $shortcode, ?string $branch, string $commit, ?string $testCommit, ?string $upgradeBranch, bool $hasTestDomain): ?string` (the sentence to refuse with, or null when the scan may be imported); column `std_scan_sites.test_commit CHAR(40) NULL`.

- [ ] **Step 1: Write the failing tests** for the rule, called only when a scanned site's commit differs from its server's:

```php
// allowed (null)
refusal('demo', 'green', $a, null, 'green', false)        // on its upgrade branch, as today
refusal('demo', 'green', $a, $b,   'green', true)         // upgrade branch wins even with a test domain
refusal('demo', 'work',  $a, $a,   null,    true)         // at the test domain's commit
refusal('demo', null,    $a, $a,   null,    true)         // detached HEAD at the test domain's commit
// refused
refusal('demo', 'work', $a, $b,   null,    true)   // contains 'its test domain runs' and substr($b, 0, 7)
refusal('demo', 'work', $a, null, null,    true)   // contains 'its test domain was not asked'
refusal('demo', 'work', $a, $a,   null,    false)  // contains 'has no upgrade branch or test domain on record'
refusal('demo', 'work', $a, $a,   'green', true)   // contains 'its upgrade branch is green'
refusal('demo', null,   $a, null, null,    false)  // contains 'a detached HEAD'
```

Every refusal starts `site demo was checked on branch work` (or `on a detached HEAD`) `, not at the commit its server runs, `, keeping today's wording for the two existing cases.

- [ ] **Step 2: Run and see them fail** (class not found).

- [ ] **Step 3: Implement `OffServerScan`** as a `final class` with that one static method, modelled on `TestDomainRule`. Then in `importSite`:
  - add to the site SELECT `COALESCE(test_site_domain_id, test_ec2_site_domain_id) AS named_test`;
  - replace the inline branch check with a call to `OffServerScan::refusal(...)`, throwing `ScanRefusedException` with its sentence;
  - add `test_commit` to the `INSERT INTO std_scan_sites` column list, after `server_commit`.

- [ ] **Step 4: Write the migration**

```sql
-- The commit a site's test domain ran when the site was scanned, as the
-- scanner was told over ssh. Set only when the scanner asked: the checkout
-- was not at the server's commit and the site has no upgrade branch. A scan
-- whose commit_sha equals it read what the test domain runs. NULL otherwise,
-- and for scans before this column.
-- Apply before the next scan: the importer writes this column.

ALTER TABLE std_scan_sites
    ADD COLUMN test_commit CHAR(40) NULL AFTER server_commit;
```

Check `server_commit`'s own type in `db_schemas/47_standards_server/` and use the same one.

- [ ] **Step 5: Run the three gates.** Expected: all pass.

- [ ] **Step 6: Commit** in two commits, rule first:

```bash
git add classes/Inventory/Standards/OffServerScan.php Tests/Unit/StandardsOffServerScanTest.php
git commit -m "Add the rule for a scan off the server's commit" -m "Two cases are allowed: the site's upgrade branch, and the commit its test domain runs. A site with an upgrade branch never uses the second." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
git add classes/Inventory/Standards/StandardsImporter.php db_schemas/55_standards_test_commit
git commit -m "Importer: accept and store a test-domain scan" -m "Uses the new rule in place of the branch-name check, and keeps the test domain's commit with the scan so the board can say what was read." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 4: The board says what the checks read

**Files:**
- Modify: `classes/Inventory/Standards/Board.php` (the row built near line 333, and its array shapes)
- Modify: `templates/admin/dh/standards.tpl.php` (lines 134 to 141)
- Test: the existing Unit test that covers the board's site row, if one builds it without a database; otherwise none (the template is out of phpcs and unit scope).

**Interfaces:**
- Consumes: `std_scan_sites.test_commit` from Task 3.
- Produces: each board site row has `'test_commit' => ?string`.

- [ ] **Step 1: `Board`** reads `test_commit` from the latest scan row the way `php_target` is read: `array_key_exists('test_commit', $latest[0])` first, so the page renders before migration 55 is applied. Add the key to the three array shapes.

- [ ] **Step 2: Template.** Inside the existing `checked_commit !== server_commit` block:

```php
<?php if ($site['test_commit'] !== null && $site['checked_commit'] === $site['test_commit']): ?>
    the checks read <code><?= $h(substr($site['checked_commit'], 0, 7)) ?></code>,
    which its test domain runs;
<?php else: ?>
    (the existing "the checks read ... the line it is preparing;" lines, unchanged)
<?php endif; ?>
```

- [ ] **Step 3: Run the three gates.** Expected: all pass.

- [ ] **Step 4: Commit** in two commits: `Board.php` (`Board: carry the test domain's commit`), then the template (`Standards page: say when checks read the test domain`).

---

### Task 5: Tell the skill and the docs

**Files:**
- Modify: `CLAUDE.md` (the "Deployed means the server's commit" bullet)
- Modify: `~/.claude/skills/next-mg-step/SKILL.md` (section 4 step 1b, and the scan reminder in section 5); this is a different git repo, `~/.claude`.
- Modify: `docs/superpowers/specs/2026-11-13-scan-follows-test-domain-design.md` only if the build departed from it.

- [ ] **Step 1: `CLAUDE.md`.** Add after the `--upgrade=<branch>` sentence: a site with a test domain and no upgrade branch is also scanned when its checkout is at the commit the test domain runs and the server's commit is inside it; the page says the checks read what the test domain runs, and the importer refuses any other off-server scan.

- [ ] **Step 2: Skill, step 1b.** Add a case ahead of "It isn't": when `site.sh sites` shows a `test` for the shortcode and `upgrade_branch` is null, say in one line that mg will scan this site once the checkout's commit is deployed to `<test.domain>`, and do nothing else. Keep the one-line-site rule and the `base` note.

- [ ] **Step 3: Skill, section 5.** For such a site the reminder reads: deploy to the test domain, then run `scan.sh <shortcode>` on Lemur 13. Read the section first and match its wording.

- [ ] **Step 4: Commit** each repo: `CLAUDE.md` here (`CLAUDE.md: a scan can follow the test domain`), the skill in `~/.claude` (`next-mg-step: scan after a test-domain deploy`).

- [ ] **Step 5: Hand back to Rob** with, in order: deploy this branch to mg; apply migration 55 at `/admin/migrate_tables.php`; then `scan.sh <shortcode>` for a site whose test domain runs its checkout. Say that the importer fails every scan between the deploy and the migration.
