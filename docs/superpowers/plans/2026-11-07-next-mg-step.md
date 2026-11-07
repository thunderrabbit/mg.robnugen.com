# Standards steps in the API, and `/next-mg-step`: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agents can ask mg which standards step their repo owes, and a global `/next-mg-step` skill does the first one and hands back to Rob to deploy and scan.

**Architecture:** A read-only v1 handler `_standards.php` finds one site by checkout path or shortcode through `Inventory\Standards\Board`, and shapes the page's own `SiteReport` into JSON with a new pure class `SiteAnswer`. So the page and the API share one set of rules. The skill is a `SKILL.md` in `~/.claude/skills/next-mg-step/` that calls it through `mg_api`.

**Tech Stack:** PHP 8.5, PDO, Codeception Unit suite, phpcs (PSR-12), PHPStan (level in `phpstan.neon`), Markdown skill file.

**Spec:** `docs/superpowers/specs/2026-11-07-next-mg-step-design.md`

## Global Constraints

- Work on branch `standards-api`, inside the #806 bubble (BEGIN `b304944`, SEMVER already `0.63.0`). Never check out or merge into master.
- Commits: imperative subject ≤50 chars, no `feat:` prefix (the repo's voice, e.g. `Add the MySQL 8.4 reserved-words step`). Body says why. Multiple `-m` flags, never HEREDOC. End with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Before each commit: `~/work/rob/standards-mcp/run-unit.sh`, `run-phpcs.sh` and `run-phpstan.sh` on `~/work/rob/mg.robnugen.com` all pass. The pre-commit hook also runs phpcs and PHPStan.
- This repo is public. Tests, comments and docs use `mg`, `example` or made-up shortcodes, never real site names.
- Only user 1 may read standards (same as `Inventory\AdminPage::guard`). The answer never carries domains, DreamHost usernames, ssh aliases or server paths.
- "Every site is currently working": no warning or alarm wording anywhere, including the skill.
- A step is ticked only by the scanner. Nothing here writes standards data.

## Review Focus

1. **The skill sends `checkout_path` with `~/work/`, an absolute path, or a trailing slash.** Expected: a 400 naming the expected form (relative to `~/work`), not a silent `{site: null}` that tells the agent "not a site". Pinned in Task 2 (`testAnAbsolutePathIsRefusedWithTheExpectedForm`, `testATrailingSlashIsRefused`).
2. **Both `checkout_path` and `shortcode`, or neither.** Expected: 400. Pinned in Task 2.
3. **A step in status NAh, and a site where every step passes.** Expected: the NAh step isn't in `open_steps`; an up-to-date site gives `open_steps: []` and mark `check`. Pinned in Task 3.
4. **A step that was never scanned, or whose check said "could not tell" (`unknown`).** Expected: it's open, the same as on the page. Pinned in Task 3.
5. **A Rob step after an agent step in display order.** Expected: `open_steps` keeps display order exactly, so the skill's "take the first" matches the page. Pinned in Task 3.

---

### Task 1: `StepLine` carries its check

**Files:**
- Modify: `classes/Inventory/Standards/StepLine.php`
- Modify: `classes/Inventory/Standards/SiteReport.php:88-99`
- Modify: `Tests/Unit/StandardsSiteStandingTest.php:20`
- Test: `Tests/Unit/StandardsSiteReportTest.php`

**Interfaces:**
- Produces: `StepLine` gains two trailing constructor properties, `public string $checkName` and `public ?string $checkParam`. `SiteReport::build` fills them from the step row's `check_name` / `check_param`.

- [ ] **Step 1: Write the failing test** in `StandardsSiteReportTest`:

```php
public function testALineCarriesItsCheckNameAndParam(): void
{
    $report = SiteReport::build(
        'mg',
        $this->reachableFacts(),
        [$this->step(self::STEP, 'phpstan_top', '10')],
        [],
        [],
        ['mg'],
        [self::STEP],
    );

    $this->assertSame('phpstan_top', $report->lines[0]->checkName);
    $this->assertSame('10', $report->lines[0]->checkParam);
}
```

- [ ] **Step 2: Run it and see it fail.** Run `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`. Expected: FAIL, undefined property `checkName`.
- [ ] **Step 3: Add the two properties.** Add them to `StepLine`, pass them from `SiteReport::build` (`RowValue::string($row, 'check_name')`, `RowValue::stringOrNull($row, 'check_param')`), and append `'a_check', null` to the constructor call in `StandardsSiteStandingTest.php:20`.
- [ ] **Step 4: Run the three gates.** Expected: all pass.
- [ ] **Step 5: Commit** with subject `Keep each step's check on its StepLine` and body "The API will tell an agent which check script proves the step, so it can run it on its own tree."

### Task 2: `SiteQuery` reads the request

**Files:**
- Create: `classes/Inventory/Standards/SiteQuery.php`
- Test: `Tests/Unit/StandardsSiteQueryTest.php`

**Interfaces:**
- Produces: `final readonly class SiteQuery` with `public ?string $shortcode`, `public ?string $checkoutPath` (exactly one is non-null), and `public static function parse(array $query): self`. `$query` is `$_GET`-shaped, `array<mixed>`. It throws `\InvalidArgumentException` with a caller-facing message. It validates with `Names::isShortcode` and `Names::isCheckoutPath`. A non-string value is refused.

- [ ] **Step 1: Write the failing tests.** All of them call `SiteQuery::parse`:
  - `testACheckoutPathIsTaken`: `['checkout_path' => 'rob/example.test']` gives `checkoutPath === 'rob/example.test'` and `shortcode === null`.
  - `testAShortcodeIsTaken`: `['shortcode' => 'example']` gives `shortcode === 'example'`.
  - `testBothAreRefused`: `expectExceptionMessage('Give checkout_path or shortcode, not both')`.
  - `testNeitherIsRefused`: `[]`, then `expectExceptionMessage('Give checkout_path or shortcode')`.
  - `testAnAbsolutePathIsRefusedWithTheExpectedForm`: `['checkout_path' => '/home/u/work/rob/example.test']`, then `expectExceptionMessage('checkout_path is relative to ~/work, like rob/example.com')`.
  - `testATrailingSlashIsRefused`: `'rob/example.test/'` gives the same message.
  - `testADotDotPathIsRefused`: `'rob/../etc'` gives the same message.
  - `testABadShortcodeIsRefused`: `['shortcode' => 'Not OK']`, then `expectExceptionMessage('Not a shortcode')`.
  - `testAnArrayValueIsRefused`: `['shortcode' => ['x']]`, then `expectExceptionMessage('Not a shortcode')`.
- [ ] **Step 2: Run them and see them fail.** Run the Unit suite. Expected: FAIL, class `SiteQuery` not found.
- [ ] **Step 3: Implement `SiteQuery::parse`.** Use exactly the messages above. Treat a parameter that is present but empty the same as absent.
- [ ] **Step 4: Run the three gates.** Expected: all pass.
- [ ] **Step 5: Commit** with subject `Read which site an API caller means`.

### Task 3: `SiteAnswer` shapes the JSON

**Files:**
- Create: `classes/Inventory/Standards/SiteAnswer.php`
- Test: `Tests/Unit/StandardsSiteAnswerTest.php`

**Interfaces:**
- Consumes: `SiteReport` (`->standing->mark->value`, `->standing->summary`, `->lines`), and `StepLine` from Task 1.
- Produces: `final class SiteAnswer` with `public static function of(string $standard, string $shortcode, ?string $lastCheckedUtc, ?string $skippedReason, SiteReport $report): array`. It returns exactly this shape:

```php
[
    'shortcode' => string, 'standard' => string,
    'standing' => ['mark' => 'check'|'info'|'face', 'text' => string],
    'last_checked_utc' => ?string, 'skipped_reason' => ?string,
    'open_steps' => list<[
        'slug', 'track', 'title', 'whose_move' ('rob'|'agent'), 'detail',
        'check', 'check_param' (?string), 'explanation', 'how_to',
    ]>,
]
```

  `open_steps` is every line with `done === false`, in the order `$report->lines` gives. `track` is `trackName`.

- [ ] **Step 1: Write the failing tests.** Build each `SiteReport` with `SiteReport::build` from plain rows, using the same helpers as `StandardsSiteReportTest`. Copy the `reachableFacts` / `step` / `issue` helpers into the new test.
  - `testAnUpToDateSiteHasNoOpenSteps`: one step whose state is `pass`. Expect `open_steps === []` and `standing === ['mark' => 'check', 'text' => 'up to date']`.
  - `testAFailingStepIsOpenWithEverythingTheAgentNeeds`: state `['outcome' => 'fail', 'detail' => 'not yet']`, step `phpstan_top` param `'10'`. Expect `open_steps[0]` to equal the full array, with `'detail' => 'not yet'`, `'check' => 'phpstan_top'`, `'check_param' => '10'`, `'explanation' => 'Why.'`, `'how_to' => 'How.'`, `'whose_move' => 'agent'`.
  - `testANahStepIsNotOpen`: state `fail` plus an issue titled `Names::noteTitle(self::STEP, 'mg')` with status `nah`. Expect `open_steps === []`.
  - `testANeverScannedStepIsOpen`: no state. Expect one open step with detail `'not checked yet'`.
  - `testAnUnknownOutcomeIsOpen`: state `['outcome' => 'unknown', 'detail' => 'could not tell']`. Expect one open step.
  - `testOpenStepsKeepDisplayOrder`: steps `a_agent` (agent, fail) then `b_rob` (whose `rob`, fail). Expect slugs `['a_agent', 'b_rob']` and standing text `'waiting on you'`.
  - `testTheAnswerCarriesNoHostingFacts`: `json_encode` the answer. Assert it contains none of `example.test`, `exampleuser`, or `'"ssh_alias"'`.
- [ ] **Step 2: Run them and see them fail.** Expected: FAIL, class `SiteAnswer` not found.
- [ ] **Step 3: Implement `SiteAnswer::of`.**
- [ ] **Step 4: Run the three gates.** Expected: all pass.
- [ ] **Step 5: Commit** with subject `Shape one site's open steps for the API`.

### Task 4: `Board` finds one site

**Files:**
- Modify: `classes/Inventory/Standards/Board.php`

**Interfaces:**
- Consumes: `Board::site()` (private, unchanged).
- Produces:
  - `public function shortcodesAtCheckout(string $checkoutPath): array`, returning `list<string>`. It runs `SELECT shortcode FROM std_sites WHERE checkout_path = ? ORDER BY shortcode`.
  - `public function siteByShortcode(string $shortcode): ?array`. It returns `site()`'s array plus `'standard' => std_standards.slug` (join `std_standards` on `std_standard_id`), or null when there's no row. Its `@return` is the `site()` array shape with `standard: string` added.

No unit test: `Board` is all SQL, and the hermetic suite has no database (the same holds for the page's queries). Its rules are tested through `SiteReport` / `SiteAnswer`. Task 5's live smoke tests the queries.

- [ ] **Step 1: Add both methods.** Use the private `rows()` helper.
- [ ] **Step 2: Run the three gates.** Expected: all pass, and PHPStan accepts the array shapes.
- [ ] **Step 3: Commit** with subject `Look up one standards site by shortcode or checkout`.

### Task 5: The `/standards/site` endpoint

**Files:**
- Create: `wwwroot/api/v1/_standards.php`
- Modify: `wwwroot/api/v1/index.php` (route block, around line 187)
- Modify: `wwwroot/api/v1/openapi.yaml` (add `/standards/site` after `/stats`)

**Interfaces:**
- Consumes: `SiteQuery::parse` (Task 2), `Board::shortcodesAtCheckout` / `siteByShortcode` (Task 4), `SiteAnswer::of` (Task 3).
- Produces: `GET /api/v1/standards/site?checkout_path=…|shortcode=…`. Answers:
  - 200 `{"site": null}`: no site matches.
  - 200 `{"site": {…SiteAnswer…}}`: one site matches.
  - 400 `{"error": <SiteQuery message>}`: a bad query.
  - 403 `{"error": "Standards are for the account owner only"}`: `$auth_user_id !== 1`.
  - 403 from `require_project_perm`: the actor lacks `can_read_project`.
  - 404 `{"error": "Not found"}`: any path under `/standards` other than `/standards/site`.
  - 405 `{"error": "Method not allowed"}`: any method but GET.
  - 409 `{"error": "More than one site uses that checkout; pass shortcode", "shortcodes": [...]}`: more than one site matches.

- [ ] **Step 1: Write `_standards.php`.** Follow `_stats.php`: a header docblock listing the route, `declare(strict_types=1)`, and a `@var` block for `$pdo`, `$auth_user_id`, `$method`, `$path`. Check in this order: 405, 404, user-1 403, parse (400). Then do the lookup. A checkout path goes through `shortcodesAtCheckout` (0 → null, more than 1 → 409, exactly 1 → `siteByShortcode`). Echo `json_encode` of the result. No credit charge, because reads are free under the openapi rule.
- [ ] **Step 2: Route it.** Add `} elseif ($path === '/standards' || preg_match('#^/standards(/|$)#', $path)) { require_project_perm($auth_actor, $method); include __DIR__ . '/_standards.php';`. Limited actors are already refused by `Guards::limitedRoute`, so don't add a route there.
- [ ] **Step 3: Document it in `openapi.yaml`.** Add `GET /standards/site` with both query params and the responses above.
- [ ] **Step 4: Run the three gates.** Expected: all pass.
- [ ] **Step 5: Commit** with subject `Answer which standards steps a site still owes`.
- [ ] **Step 6: Close the bubble.** Use the `close-bubble` skill: `git close-bubble --dry-run "DONE #806 standards steps in the API"`, then for real. Ask Rob before pushing, and tell him to pull `standards-api` on mg.
- [ ] **Step 7: Smoke-test live, after Rob says it's deployed.** First confirm with `ssh mg "cd mg.robnugen.com && git log -1 --oneline"`. Then use `mcp__jikan__mg_api`:
  - `GET /standards/site?checkout_path=rob/mg.robnugen.com`: shortcode `mg`, one open step `rector_type_declarations`, standing text `1 step to go`.
  - `GET /standards/site?checkout_path=rob/no-such-repo`: `{"site": null}`.
  - `GET /standards/site?shortcode=mg&checkout_path=rob/mg.robnugen.com`: 400.
  - `GET /standards/site?checkout_path=/home/x`: 400 with the relative-path message.
  - `POST /standards/site`: 405.

### Task 6: The `/next-mg-step` skill

**Files:**
- Create: `~/.claude/skills/next-mg-step/SKILL.md` (committed in the `~/.claude` repo, not mg)

**Interfaces:**
- Consumes: the live endpoint from Task 5.

- [ ] **Step 1: Write the `SKILL.md`.** Use the frontmatter `name: next-mg-step`. The description reads: "Do this repo's first open step on mg's standards page, or say plainly there is none. Use only when Rob types /next-mg-step." The procedure, in this order:
  1. Run `git rev-parse --show-toplevel` and strip `$HOME/work/`. If the repo isn't under `~/work/`, say "This repo isn't under ~/work, so it can't be on mg's standards page," and stop.
  2. Call `mcp__jikan__mg_api` with `GET /standards/site?checkout_path=<path>`. An error: quote it and stop. If the call isn't available (no jikan MCP), say so and stop.
  3. `site` is null: say "This repo isn't on mg's standards page, so /next-mg-step has nothing for it," and stop.
  4. `open_steps` is empty: say "<shortcode> is up to date on mg's standards page," and stop.
  5. `standing.mark` is `face`: say "Nobody can reach <shortcode> yet," list the open `rob` steps with their how-to, and stop.
  6. `skipped_reason` is set: tell Rob the steps come from the scan of `last_checked_utc`, quote the reason, then go on.
  7. The first open step has `whose_move` `rob`: give Rob its title, detail and how-to, and stop.
  8. The first step is the agent's: say which step it is. If `~/work/rob/mg.robnugen.com/tools/site-standards/checks/<check>.sh` exists, run it with the repo path (and `check_param`). If it exits 0, say "Already done here; it's waiting on a deploy," give the scan reminder, and stop. Otherwise, compare the how-to with the code, then do the step under this project's own rules from its memory, `CLAUDE.md` and `git log` (bubble, BEGIN, gates), with brainstorming or a plan if the step is big. Re-run the check when done. If the step doesn't fit the site, don't decide alone: suggest Rob file `<step>.<shortcode>` in the site's project as NAh, and stop.
  9. End with the reminder: "Once you've approved and deployed this, run on Lemur 13: `~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh <shortcode>`."

  Include the rule: one step per call; never mark a step done; no warning wording.
- [ ] **Step 2: Dry-run it by reading.** Walk the five cases against the smoke answers from Task 5 (mg: agent step; `rob/no-such-repo`: null) and check that each case lands on one stop wording.
- [ ] **Step 3: Commit in `~/.claude`.** Subject: `Add the /next-mg-step skill`.
- [ ] **Step 4: Hand over.** Tell Rob to try `/next-mg-step` on mgClaude. The first real run is mg's Rector type-declarations step.
