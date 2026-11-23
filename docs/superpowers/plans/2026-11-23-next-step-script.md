# Next-Step Script Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/next-mg-step` stops being 170 lines of prose a model applies by judgement. A script does the lookup and prints the same words for the same facts; each agent step names its model; a `haiku` or `sonnet` step runs in a subagent on that model, an `opus` or `fable` step in the session; the script verifies the work before the session closes the bubble.

**Architecture:** Step and track files gain a `model:` line, validated by `StepTree` and stored nowhere else. A new `site.sh next-step` command on mg answers over ssh with the site's `SiteAnswer`, its facts and the commit of the last scan's step files, built by a class the v1 handler shares. `tools/site-standards/next_step.py` on Lemur 13 turns that into one JSON outcome (`say`, `ask`, `dispatch`, `do`), reads the model from the step files at that commit with git, and in `--confirm` checks the work itself. The skill file becomes a short dispatcher.

**Tech Stack:** PHP 8.5 and Codeception Unit (mg side); Python 3 standard library and unittest, bash, git, ssh (Lemur 13 side).

**Spec:** `docs/superpowers/specs/2026-11-14-next-step-script-design.md` (revised 2026-11-23)

## How this plan is laid out

Two parts, written by two agents against one fixed interface (the JSON that
`site.sh next-step` prints; both parts state it, and they agree). **Task
numbers start again at 1 in each part**: "Task 6" inside Part 2 means Part
2's Task 6.

Three bubbles in a row, each stacked on the one before, none nested:

| Bubble | Branch | Opens at | Holds | Tasks |
|---|---|---|---|---|
| A | `next-step-script` | 0.90.0 (`ec6a41d`, already there) | the spec, this plan, step models | Part 1, 1 to 4 |
| B | `next-step-command` | 0.91.0 | the shared lookup class, `site.sh next-step` | Part 1, 5 to 10 |
| C | `next-step-lookup` | 0.92.0 | the script, the brief template, `CLAUDE.md` | Part 2, 1 to 8 |

Rob deploys after each bubble or after all three; nothing in A or B changes
what the present skill sees. Part 2's Tasks 9 and 10 (the live trial, then
the new skill file in `~/.claude`) wait until he has deployed C and run a
scan.

## Global Constraints

- Never commit on, check out or merge into master. Never push mg. Bubbles
  close with `git close-bubble` (dry run first).
- The repo is public: no real shortcode, site name, domain or ssh alias in
  code, tests, templates, step files or commit messages.
- No database change: no migration, no new column.
- The script never writes to a repo and never changes anything on mg.
- No default model: an agent step with none is refused when the step files
  are read.
- A lookup never runs a site's safe suite.
- No warning or alarm wording in anything shown to Rob.
- Gates before a bubble closes: `run-unit.sh`, `run-phpcs.sh`,
  `run-phpstan.sh`, the `run-rector.sh` dry run, and
  `python3 -I -m unittest discover -s tools/site-standards/tests`.
- Commits use several `-m` flags, never a heredoc, one layer per commit, and
  end with the `Co-Authored-By` line (written `<attribution line>` below).

## Open for Rob

Each part ends with its own short list. The ones that change behaviour:

1. A `whose_move: rob` step in a track that names a model carries that model
   in `StepDef`, unread; the spec said null. (Part 1, question 1.)
2. A step whose check is a safe suite is never `clean`, so its bubble is
   always left for you. (Part 2, question 2.)
3. Four or more commits with no one-file BEGIN first is reported but does
   not make `clean` false; `git close-bubble` then refuses by itself. I
   would make it false. (Part 2, question 3.)
4. The script learns the session's model from a `--session-model=` flag the
   skill passes; it cannot see it otherwise.

---

## Part 1: step models and the next-step command

Two bubbles, in this order. Bubble A (Tasks 1 to 4) is on branch
`next-step-script`, whose BEGIN commit `ec6a41d` (0.90.0) is already there.
Bubble B (Tasks 5 to 10) is stacked on A's closed tip on a new branch,
`next-step-command`, opened at 0.91.0.

**Rules for every task in this part**

- Never commit on, check out or merge into `master`. Never push. Never ssh
  anywhere: nothing here needs the server until Rob deploys.
- Every commit: `git commit -m "<subject>" -m "<body: why>" -m "<attribution line>"`.
  Subject at most 50 characters, imperative, capitalised, no full stop.
  Never a heredoc. One layer per commit.
- The pre-commit hook runs phpcs on each staged PHP file and PHPStan
  (level 10, `phpstan.neon`) over the staged tree. Fix what it reports;
  never `--no-verify`, never a baseline, never a blanket ignore.
- The hook does not run the Unit suite. Run it yourself:
  `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`
  (it runs the whole suite; there is no per-test filter). "Expected: FAIL"
  below names the tests that must be the failing ones.
- The repo is public. Fixtures use `mg`, `example`, `example-blog`,
  `example.test`, `rob/example.test`, `exampleuser`. No real shortcode, site
  name, domain or ssh alias in code, tests, step files or commit messages.
- New classes: `declare(strict_types=1)`, `final`, `readonly` where they can
  be, PSR-12. PHP 8.5.
- Nothing is stored in the database: no migration, and no change to
  `StandardsImporter`, `StepLine`, `SiteAnswer`, `SiteReport` or `SiteStore`.

**What this part produces for Part 2 (the Python script)**

`tools/site-standards/site.sh next-step --checkout=<path>` or
`--shortcode=<code>` prints one line of JSON and a newline on stdout:

```json
{"site": {...} | null, "shortcodes": [...], "facts": {...} | null, "steps_commit": "<40 hex>" | null}
```

| Case | stdout | stderr | exit |
|---|---|---|---|
| One site matches | `site` = the `SiteAnswer` object (same keys as `GET /standards/site`), `shortcodes` = `[]`, `facts` = that site's entry from `site.sh sites`, unchanged | empty | 0 |
| No site at that checkout, or no site with that shortcode | `site` null, `shortcodes` `[]`, `facts` null | empty | 0 |
| More than one site uses the checkout | `site` null, `shortcodes` = every shortcode, sorted, `facts` null | empty | 0 |
| Arguments refused (see below) | nothing | one sentence and a newline | 1 |
| ssh itself fails | nothing | ssh's own words | 255 (ssh's) |

`steps_commit` is in every exit-0 answer: the `steps_commit` of the newest
row of `std_scans` (by `scanned_at_utc`), whichever sites that scan covered,
or null when mg has never imported a scan.

`--checkout=` takes the path **relative to `~/work`**, as
`std_sites.checkout_path` holds it and as `site-set --checkout=` takes it
(`rob/example.test`). The Python script strips the `~/work/` prefix before
calling. An absolute path is refused.

The refusals, word for word (all exit 1, stderr only):

| Arguments | Sentence |
|---|---|
| neither option, or only empty ones | `next-step needs --checkout= or --shortcode=.` |
| both | `next-step takes --checkout= or --shortcode=, not both.` |
| `--shortcode=` fails `Names::isShortcode` | `--shortcode= is not a shortcode.` |
| `--checkout=` fails `Names::isCheckoutPath` (absolute, trailing slash, `..`) | `--checkout= is relative to ~/work, like rob/example.com.` |
| any other option | `next-step does not take --<name>.` (the existing sentence) |
| mg has not deployed the command yet | `Unknown command: next-step. Use one of: sites, ...` (the existing sentence) |

A refusal is decided in `SiteCommand::parse`, before the database is opened,
as for every other sub-command. A database failure is not caught, as today:
PHP's uncaught-exception text and exit 255.

`facts` has exactly the keys `SiteStore::forScanner()` gives each site:
`shortcode`, `standard`, `checkout_path`, `server` (`{ssh_alias, directory}`
or null), `server_problem`, `php_current`, `test` (`{domain, php_version,
ssh_alias, directory}` or null), `upgrade_branch`.

---

### Task 1: `StepTree` reads `model:` from step and track files

**Files:**
- Create: `classes/Inventory/Standards/StepModel.php`
- Modify: `classes/Inventory/Standards/StepDef.php`, `classes/Inventory/Standards/StepTree.php`
- Test: `Tests/Unit/StandardsStepTreeTest.php`

**Interfaces:**
- Produces: `enum Inventory\Standards\StepModel: string` with cases
  `Haiku = 'haiku'`, `Sonnet = 'sonnet'`, `Opus = 'opus'`, `Fable = 'fable'`.
- Produces: `StepDef::__construct(string $slug, float $position, string $title, string $explanation, string $howTo, StepStatus $status, WhoseMove $whoseMove, string $checkName, ?string $checkParam, ?StepModel $model)`.
  `$model` is the tenth and last parameter, with no default. `StepTree::step()`
  is the only place that constructs a `StepDef`.
- After this task a model is read and resolved but not yet required; Task 3
  adds the refusal, once Task 2 has put the lines in the real files.

Resolution rule (the same for every step, whoever's move it is): the step's
own `model:` line, else its track's `model:` line, else null.

- [ ] **Step 1: Add the enum and commit it.**

```php
<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The model a step names for the agent that does it: the names the Agent
 * tool's `model` parameter takes. A step file says it with a `model:` line,
 * or its track's _track.md says it for every step in the track.
 */
enum StepModel: string
{
    case Haiku = 'haiku';
    case Sonnet = 'sonnet';
    case Opus = 'opus';
    case Fable = 'fable';
}
```

```bash
git add classes/Inventory/Standards/StepModel.php
git commit -m "Add StepModel, the models a step can name" -m "Each agent step will say which model does it, so /next-mg-step can run a mechanical step on a cheaper model than a restructuring one. These are the names the Agent tool takes today." -m "<attribution line>"
```

- [ ] **Step 2: Write the failing tests** in `Tests/Unit/StandardsStepTreeTest.php`.

Add `use Inventory\Standards\StepModel;`. Change the `GOOD` step so it names
its own model (every test that copies `self::GOOD[self::STEP]` into another
track then stays valid after Task 3):

```php
        self::STEP =>
            "---\ntitle: \"Rector's 8.5 rules, one per commit\"\nstatus: proven\n"
            . "whose_move: agent\ncheck: rector_level\nparam: 8.5\nmodel: sonnet\n---\nWhy it matters.\n\n"
            . "## How to do it\n\n1. Do this.\n",
```

Add one assertion to `testParsesAStandardATrackAndAStep`:

```php
        $this->assertSame(StepModel::Sonnet, $step->model);
```

Add these tests:

```php
    public function testAStepTakesItsTracksModelWhenItNamesNone(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("model: sonnet\n", '', $files[self::STEP]);
        $files['template/rector/_track.md'] = "---\nname: Rector\nposition: 30\nmodel: opus\n---\n";

        $this->assertSame(StepModel::Opus, StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model);
    }

    public function testAStepsOwnModelBeatsItsTracks(): void
    {
        $files = self::GOOD;
        $files['template/rector/_track.md'] = "---\nname: Rector\nposition: 30\nmodel: opus\n---\n";

        $this->assertSame(StepModel::Sonnet, StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model);
    }

    public function testEveryModelNameIsAccepted(): void
    {
        foreach (['haiku', 'sonnet', 'opus', 'fable'] as $name) {
            $files = self::GOOD;
            $files[self::STEP] = str_replace('model: sonnet', "model: {$name}", $files[self::STEP]);

            $this->assertSame($name, StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model?->value);
        }
    }

    public function testRefusesAnUnknownModelOnAStep(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace('model: sonnet', 'model: gpt', $files[self::STEP]);

        $this->refuses($files, '#10_rector_php_8_5_rules\.md: model must be haiku, sonnet, opus or fable#');
    }

    public function testRefusesAModelInCapitals(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace('model: sonnet', 'model: Sonnet', $files[self::STEP]);

        $this->refuses($files, '/model must be/');
    }

    public function testRefusesAnUnknownModelOnATrackEvenWithNoSteps(): void
    {
        $files = self::GOOD + [
            'template/groundwork/_track.md' => "---\nname: Groundwork\nposition: 10\nmodel: best\n---\n",
        ];

        $this->refuses($files, '#template/groundwork/_track\.md: model must be#');
    }

    public function testRefusesAModelOnAStandard(): void
    {
        $files = self::GOOD;
        $files['template/_standard.md'] = "---\nname: Template sites\nmodel: sonnet\n---\n";

        $this->refuses($files, '#_standard\.md: unknown frontmatter key "model"#');
    }

    public function testRobsStepMayNameNoModel(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace(
            ['whose_move: agent', "model: sonnet\n"],
            ['whose_move: rob', ''],
            $files[self::STEP],
        );

        $this->assertNull(StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model);
    }
```

- [ ] **Step 3: Run the Unit suite.**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`
Expected: FAIL. Almost every `StandardsStepTreeTest` test fails with
`unknown frontmatter key "model"` (the fixture now carries the line).

- [ ] **Step 4: `StepDef` gains the property.** After `public ?string $checkParam,` add:

```php
        public ?StepModel $model,
```

- [ ] **Step 5: `StepTree` reads and resolves it.**

In the class docblock, the file list becomes:

```
 *     <standard>/_standard.md          frontmatter: name
 *     <standard>/<track>/_track.md     frontmatter: name, position,
 *                                      model (optional)
 *     <standard>/<track>/<number>_<step>.md
 *                                      frontmatter: title, status, whose_move,
 *                                      check, param (optional), model (optional)
```

and add this paragraph before "Anything else is refused":

```
 * A step's model is its own "model" line, else its track's. It names the
 * model an agent does the step on (StepModel).
```

In `fromFiles`, the track's frontmatter call takes `['model']` as its
optional list, and the model is read straight after it:

```php
                $trackFields = self::frontmatter(
                    $trackPath,
                    $trackFiles[$standard][$track],
                    ['name', 'position'],
                    ['model'],
                )['fields'];
                $trackModel = self::model($trackPath, $trackFields['model'] ?? null);
```

The call that builds a step passes it on:

```php
                    $steps[] = self::step($name, $position, $path, $files[$path], $trackModel);
```

`step()` becomes
`private static function step(string $name, float $position, string $path, string $content, ?StepModel $trackModel): StepDef`.
Its optional list becomes `['param', 'model']`. After the `$whoseMove` lines add:

```php
        $model = self::model($path, $fields['model'] ?? null) ?? $trackModel;
```

and pass `$model` as the last argument of `new StepDef(...)`, after
`$fields['param'] ?? null`. Add the new method after `position()`:

```php
    /**
     * A "model" line's value as a StepModel; null when the file has no such line.
     */
    private static function model(string $path, ?string $value): ?StepModel
    {
        if ($value === null) {
            return null;
        }
        return StepModel::tryFrom($value)
            ?? throw new StandardsFormatException("{$path}: model must be haiku, sonnet, opus or fable");
    }
```

(`frontmatter()` already refuses an empty `model:` and a second `model:` line.)

- [ ] **Step 6: Run the Unit suite.** Expected: PASS, including
  `StandardsRealStepFilesTest` (the real files have no `model:` line yet and
  none is required yet) and `StandardsScanPayloadTest`.

- [ ] **Step 7: Commit.**

```bash
git add classes/Inventory/Standards/StepDef.php classes/Inventory/Standards/StepTree.php Tests/Unit/StandardsStepTreeTest.php
git commit -m "StepTree: read model from step and track files" -m "A step file or its track's _track.md may now name the model that does the step; the step's own line wins. Nothing requires the line yet, so the step files deployed today still load." -m "<attribution line>"
```

### Task 2: The real step files name their models

**Files:**
- Modify (add `model: sonnet` as the last frontmatter line, after `position:`), eleven track files:
  - `tools/site-standards/steps/template/codeception/_track.md`
  - `tools/site-standards/steps/template/features/_track.md`
  - `tools/site-standards/steps/template/foundations/_track.md`
  - `tools/site-standards/steps/template/groundwork/_track.md`
  - `tools/site-standards/steps/template/javascript/_track.md`
  - `tools/site-standards/steps/template/mysql/_track.md`
  - `tools/site-standards/steps/template/phpstan/_track.md`
  - `tools/site-standards/steps/template/php_version/_track.md`
  - `tools/site-standards/steps/template/rector/_track.md`
  - `tools/site-standards/steps/template/tooling/_track.md`
  - `tools/site-standards/steps/wordpress/core/_track.md`
- Modify (add `model: opus` after `position:`): `tools/site-standards/steps/template/mlaphp/_track.md`
- Modify (add `model: opus` as the last frontmatter line, after `param:`), five step files:
  - `tools/site-standards/steps/template/phpstan/060_phpstan_level_6.md`
  - `tools/site-standards/steps/template/phpstan/070_phpstan_level_7.md`
  - `tools/site-standards/steps/template/phpstan/080_phpstan_level_8.md`
  - `tools/site-standards/steps/template/phpstan/090_phpstan_level_9.md`
  - `tools/site-standards/steps/template/phpstan/100_phpstan_level_10_no_baseline.md`
- Leave alone: `template/phpcs/_track.md` and `template/security/_track.md`
  (no step files), and `wordpress/groundwork/_track.md` (both its steps are
  `whose_move: rob`). A track with no agent step gets no line until it has one.
- Test: `Tests/Unit/StandardsRealStepFilesTest.php`

Levels 0 to 5 of phpstan get `sonnet` from the track line and carry no line
of their own. The three `whose_move: rob` steps under mlaphp
(`040_functions_into_classes`, `079.5_one_database_namespace`,
`080_sql_into_gateways`) and the three under template/groundwork and two
under php_version carry no line; they resolve to their track's model, which
nothing reads.

- [ ] **Step 1: Write the failing test.** In
  `Tests/Unit/StandardsRealStepFilesTest.php` add
  `use Inventory\Standards\WhoseMove;`, collect the agent steps with no model
  inside the existing loops, and assert there are none:

```php
        $steps = 0;
        $unnamed = [];
        foreach (StepTree::fromFiles($files)->standards as $standard) {
            foreach ($standard->tracks as $track) {
                $steps += count($track->steps);
                foreach ($track->steps as $step) {
                    if ($step->whoseMove === WhoseMove::Agent && $step->model === null) {
                        $unnamed[] = "{$standard->slug}/{$track->slug}/{$step->slug}";
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $steps);
        $this->assertSame([], $unnamed, 'Every agent step names a model, itself or through its track.');
```

  Do not pin which model a step has: changing one is meant to be a one-word
  commit in one file.

- [ ] **Step 2: Run the Unit suite.** Expected: FAIL, only
  `StandardsRealStepFilesTest::testTheStepFilesInThisRepoLoad`, listing every
  agent step (`template/codeception/phpunit_tests_under_codeception`, ...).

- [ ] **Step 3: Add the seventeen lines** listed under Files. A track file
  ends up as, for example:

```
---
name: PHPStan
position: 40
model: sonnet
---
```

  and `060_phpstan_level_6.md` opens:

```
---
title: PHPStan level 6, no baseline
status: proven
whose_move: agent
check: phpstan_level
param: 6
model: opus
---
```

- [ ] **Step 4: Count them.**

Run: `grep -rlx 'model: sonnet' tools/site-standards/steps | wc -l` Expected: `11`
Run: `grep -rlx 'model: opus' tools/site-standards/steps | wc -l` Expected: `6`
Run: `git status --short | wc -l` Expected: `18` (17 step files and the test; no new file)

- [ ] **Step 5: Run the Unit suite.** Expected: PASS.

- [ ] **Step 6: Run the Python tests.** `scan.py` reads `check` and `param`
  from each step file's frontmatter and must not mind the new line.

Run: `cd ~/work/rob/mg.robnugen.com && python3 -I -m unittest discover -s tools/site-standards/tests`
Expected: `OK`

- [ ] **Step 7: Commit** (one change across many files is one commit).

```bash
git add tools/site-standards/steps Tests/Unit/StandardsRealStepFilesTest.php
git commit -m "Step files: name the model for agent steps" -m "A first guess for Rob to change: sonnet as the default of every track with agent steps, opus for MLAPHP and for PHPStan levels 6 to 10, where the work is restructuring and Rob redirects it. Tracks with no agent step get no line." -m "<attribution line>"
```

### Task 3: An agent step with no model is refused

**Files:**
- Modify: `classes/Inventory/Standards/StepTree.php`
- Test: `Tests/Unit/StandardsStepTreeTest.php`, `Tests/Unit/StandardsScanPayloadTest.php`

**Interfaces:**
- Consumes: Task 1's `$model` in `StepTree::step()`.
- Produces: for every `StepDef` with `whoseMove === WhoseMove::Agent`,
  `model` is non-null. There is no default model anywhere.

- [ ] **Step 1: Write the failing tests** in `StandardsStepTreeTest`:

```php
    public function testRefusesAnAgentStepWithNoModel(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace("model: sonnet\n", '', $files[self::STEP]);

        $this->refuses($files, '#10_rector_php_8_5_rules\.md: an agent step needs a model#');
    }

    public function testRobsStepInATrackWithAModelIsAccepted(): void
    {
        $files = self::GOOD;
        $files[self::STEP] = str_replace(
            ['whose_move: agent', "model: sonnet\n"],
            ['whose_move: rob', ''],
            $files[self::STEP],
        );
        $files['template/rector/_track.md'] = "---\nname: Rector\nposition: 30\nmodel: opus\n---\n";

        $this->assertSame(StepModel::Opus, StepTree::fromFiles($files)->standards[0]->tracks[0]->steps[0]->model);
    }
```

- [ ] **Step 2: Run the Unit suite.** Expected: FAIL, only
  `testRefusesAnAgentStepWithNoModel` ("The step files were accepted.").

- [ ] **Step 3: Add the refusal** in `StepTree::step()`, straight after the
  `$model = ...` line:

```php
        if ($whoseMove === WhoseMove::Agent && $model === null) {
            throw new StandardsFormatException(
                "{$path}: an agent step needs a model: add a \"model\" line here or to its track's _track.md"
            );
        }
```

  Extend the docblock paragraph added in Task 1 to:

```
 * A step's model is its own "model" line, else its track's. It names the
 * model an agent does the step on (StepModel). An agent's step with neither
 * is refused: there is no default. Rob's step needs none.
```

- [ ] **Step 4: Run the Unit suite.** Expected: FAIL, now in
  `StandardsScanPayloadTest`: its fixture step is an agent's with no model.

- [ ] **Step 5: Fix that fixture.** In `Tests/Unit/StandardsScanPayloadTest.php`,
  `good()`:

```php
                    . "whose_move: agent\ncheck: phpstan_top\nmodel: sonnet\n---\nWhy.\n",
```

- [ ] **Step 6: Run the Unit suite.** Expected: PASS (the real step files
  load because Task 2 came first).

- [ ] **Step 7: Commit.**

```bash
git add classes/Inventory/Standards/StepTree.php Tests/Unit/StandardsStepTreeTest.php Tests/Unit/StandardsScanPayloadTest.php
git commit -m "StepTree: refuse an agent step with no model" -m "A step with no model named must never be quietly run on something, so there is no default: the file is refused when the step files are read, like any other malformed one. The lines went in with the previous commit, so the rule and the files deploy together." -m "<attribution line>"
```

### Task 4: Close bubble A

- [ ] **Step 1: All gates, from the repo root.**

```bash
~/work/rob/standards-mcp/run-unit.sh    ~/work/rob/mg.robnugen.com
~/work/rob/standards-mcp/run-phpcs.sh   ~/work/rob/mg.robnugen.com
~/work/rob/standards-mcp/run-phpstan.sh ~/work/rob/mg.robnugen.com
~/work/rob/standards-mcp/run-rector.sh  ~/work/rob/mg.robnugen.com
python3 -I -m unittest discover -s tools/site-standards/tests
```

Expected: the first three exit 0. Rector's dry run exits 2 and names exactly
two files, `Tests/Unit/Issues/PdoImageStoreTest.php` and
`Tests/Unit/Issues/ImageServiceTest.php`; both were there before this branch
and are not ours. Any other file it names is: apply that one rule
(`--apply --only=<FQCN>`), tidy with phpcbf, commit as
`Rector <RuleName>: <what changed>`. Python: `OK`. Then
`check_tests(repo="mg")` returns `status: pass`.

- [ ] **Step 2: `git status` is clean, `git branch --show-current` is `next-step-script`.**

- [ ] **Step 3: Close.**

```bash
git close-bubble --dry-run
```

Expected: it names `ec6a41d BEGIN script for the next-mg-step lookup` as the
BEGIN commit. If it names any other commit, stop and tell Rob.

```bash
git close-bubble "DONE steps name their model"
```

Do not push. Tell Rob the bubble is closed on `next-step-script`, that it
deploys on its own (nothing reads the model yet), and that a `model:` line
takes effect at the first scan after he deploys it.

---

### Task 5: BEGIN the second bubble

**Files:**
- Modify: `version.php`

- [ ] **Step 1: Branch from A's closed tip.**

```bash
git checkout -b next-step-command
```

- [ ] **Step 2: Raise the version, and nothing else.** In `version.php`:

```php
const SENTIMENTAL_VERSION = "BEGIN next-step command over ssh";
const SEMVER = "0.91.0";
```

- [ ] **Step 3: Commit.**

```bash
git add version.php
git commit -m "BEGIN next-step command over ssh" -m "Opens the bubble for the lookup /next-mg-step's script will make over ssh: one command that answers a site's open steps, its scanner facts and the commit of the last scan's step files." -m "<attribution line>"
```

### Task 6: `SiteLookup`, the one way from a query to a `SiteAnswer`

**Files:**
- Create: `classes/Inventory/Standards/SiteSource.php`, `classes/Inventory/Standards/SiteFound.php`, `classes/Inventory/Standards/SiteLookup.php`
- Modify: `classes/Inventory/Standards/Board.php` (class line only)
- Test: `Tests/Unit/StandardsSiteLookupTest.php`

**Interfaces:**
- Consumes: `SiteQuery` (`?string $shortcode`, `?string $checkoutPath`,
  exactly one set); `SiteAnswer::of(string $standard, string $shortcode, ?string $lastCheckedUtc, ?string $skippedReason, SiteReport $report, ?string $phpCurrent, ?string $testPhp): array`;
  `Board::shortcodesAtCheckout(string $checkoutPath): array` (sorted by
  shortcode) and `Board::siteByShortcode(string $shortcode): ?array`, both
  unchanged.
- Produces:
  - `interface SiteSource` with those two methods. `Board` implements it.
  - `final readonly class SiteFound { public ?array $site; public array $shortcodes; }`
    with `__construct(?array $site, array $shortcodes)`. `$site` is
    `SiteAnswer`'s `Answer` shape or null. `$shortcodes` is non-empty only
    when more than one site uses the checkout, and then `$site` is null.
  - `SiteLookup::find(SiteQuery $query, SiteSource $source): SiteFound`.

`Board` is `final` and all SQL, so it cannot be faked or run in the hermetic
suite. The interface is what lets the lookup be tested with made-up sites.

- [ ] **Step 1: Write the failing test** `Tests/Unit/StandardsSiteLookupTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\SiteAnswer;
use Inventory\Standards\SiteFacts;
use Inventory\Standards\SiteLookup;
use Inventory\Standards\SiteQuery;
use Inventory\Standards\SiteReport;
use Inventory\Standards\SiteSource;

/**
 * Hermetic: from "which site?" to the answer the API and the next-step
 * command both give. Sites are made up.
 *
 * @phpstan-import-type FoundSite from SiteSource
 */
final class StandardsSiteLookupTest extends Unit
{
    /**
     * @return FoundSite
     */
    private function site(string $shortcode): array
    {
        $facts = new SiteFacts(
            [[
                'domain' => 'example.test',
                'php_version' => '8.5',
                'username' => 'exampleuser',
                'ssh_alias' => 'example',
                'login_confirmed_on' => '2026-11-07',
            ]],
            'rob/example.test',
            null,
        );
        $steps = [[
            'slug' => 'a_step',
            'track_name' => 'A track',
            'title' => 'A step',
            'whose_move' => 'agent',
            'check_name' => 'a_check',
            'check_param' => null,
            'explanation' => 'Why.',
            'how_to' => 'How.',
        ]];

        return [
            'standard' => 'template',
            'shortcode' => $shortcode,
            'last_checked_utc' => '2026-11-07 03:12',
            'skipped_reason' => 'made-up reason naming example.test',
            'php_current' => '8.2',
            'test_php' => '8.5',
            'report' => SiteReport::build($shortcode, $facts, $steps, [], [], [$shortcode], ['a_step']),
        ];
    }

    /**
     * @param array<string, list<string>> $atCheckout shortcodes by checkout path
     * @param list<string> $known the shortcodes that are sites
     */
    private function source(array $atCheckout, array $known): SiteSource
    {
        $sites = [];
        foreach ($known as $shortcode) {
            $sites[$shortcode] = $this->site($shortcode);
        }

        return new class ($atCheckout, $sites) implements SiteSource {
            /**
             * @param array<string, list<string>> $atCheckout
             * @param array<string, FoundSite> $sites
             */
            public function __construct(private array $atCheckout, private array $sites)
            {
            }

            public function shortcodesAtCheckout(string $checkoutPath): array
            {
                return $this->atCheckout[$checkoutPath] ?? [];
            }

            public function siteByShortcode(string $shortcode): ?array
            {
                return $this->sites[$shortcode] ?? null;
            }
        };
    }

    public function testAShortcodeFindsItsSite(): void
    {
        $found = SiteLookup::find(SiteQuery::parse(['shortcode' => 'mg']), $this->source([], ['mg']));

        $site = $this->site('mg');
        $this->assertSame(
            SiteAnswer::of('template', 'mg', '2026-11-07 03:12', $site['skipped_reason'], $site['report'], '8.2', '8.5'),
            $found->site,
        );
        $this->assertSame([], $found->shortcodes);
    }

    public function testTheAnswerIsTheApisAndKeepsPrivateTextOut(): void
    {
        $found = SiteLookup::find(SiteQuery::parse(['shortcode' => 'mg']), $this->source([], ['mg']));

        $this->assertSame(SiteAnswer::SKIPPED, $found->site['skipped_reason'] ?? null);
        $this->assertSame('a_step', $found->site['open_steps'][0]['slug'] ?? null);
    }

    public function testAnUnknownShortcodeFindsNothing(): void
    {
        $found = SiteLookup::find(SiteQuery::parse(['shortcode' => 'example']), $this->source([], ['mg']));

        $this->assertNull($found->site);
        $this->assertSame([], $found->shortcodes);
    }

    public function testACheckoutWithOneSiteFindsIt(): void
    {
        $source = $this->source(['rob/example.test' => ['example']], ['example', 'mg']);

        $found = SiteLookup::find(SiteQuery::parse(['checkout_path' => 'rob/example.test']), $source);

        $this->assertSame('example', $found->site['shortcode'] ?? null);
        $this->assertSame([], $found->shortcodes);
    }

    public function testACheckoutNoSiteUsesFindsNothing(): void
    {
        $found = SiteLookup::find(SiteQuery::parse(['checkout_path' => 'rob/other.test']), $this->source([], ['mg']));

        $this->assertNull($found->site);
        $this->assertSame([], $found->shortcodes);
    }

    public function testACheckoutWithTwoSitesNamesThemAndPicksNeither(): void
    {
        $source = $this->source(['rob/example.test' => ['example', 'example-blog']], ['example', 'example-blog']);

        $found = SiteLookup::find(SiteQuery::parse(['checkout_path' => 'rob/example.test']), $source);

        $this->assertNull($found->site);
        $this->assertSame(['example', 'example-blog'], $found->shortcodes);
    }
}
```

- [ ] **Step 2: Run the Unit suite.** Expected: FAIL,
  `StandardsSiteLookupTest` with `Interface "Inventory\Standards\SiteSource" not found`.

- [ ] **Step 3: Write `SiteSource.php`.**

```php
<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Where SiteLookup reads sites from: Board on mg, made-up sites in a test.
 *
 * @phpstan-type FoundSite array{standard: string, shortcode: string, last_checked_utc: ?string, skipped_reason: ?string, php_current: ?string, test_php: ?string, report: SiteReport}
 */
interface SiteSource
{
    /**
     * The shortcodes of every site kept in one checkout (relative to ~/work),
     * in shortcode order.
     *
     * @return list<string>
     */
    public function shortcodesAtCheckout(string $checkoutPath): array;

    /**
     * One site with its standard's slug, or null when no site has the shortcode.
     *
     * @return FoundSite|null
     */
    public function siteByShortcode(string $shortcode): ?array;
}
```

- [ ] **Step 4: `Board` implements it.** Change only the class line:

```php
final class Board implements SiteSource
```

  `Board::siteByShortcode()` keeps its own, longer return shape. PHPStan's
  array shapes are open, so the longer shape satisfies `FoundSite`. If the
  hook reports `method.childReturnType` on that method all the same, do not
  ignore it: replace `FoundSite` in `SiteSource` with the full shape copied
  from `Board::siteByShortcode()`'s `@return`, and give the test's `site()`
  the missing keys.

- [ ] **Step 5: Write `SiteFound.php`.**

```php
<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * What a SiteQuery found: one site's answer, nothing, or the shortcodes of
 * the several sites that share the checkout asked about.
 *
 * @phpstan-import-type Answer from SiteAnswer
 */
final readonly class SiteFound
{
    /**
     * @param ?Answer $site null when no site matches, and when several do
     * @param list<string> $shortcodes every site using the checkout, when there is
     *     more than one; otherwise empty
     */
    public function __construct(public ?array $site, public array $shortcodes)
    {
    }
}
```

- [ ] **Step 6: Write `SiteLookup.php`.**

```php
<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * From "which site?" to its answer. GET /standards/site and the next-step
 * command of tools/site_standards.php both ask here, so they cannot disagree
 * about which site a checkout means or what its open steps are.
 *
 * A checkout that several sites share is never picked from: the caller is
 * told which sites, and asks again by shortcode.
 */
final class SiteLookup
{
    public static function find(SiteQuery $query, SiteSource $source): SiteFound
    {
        $shortcode = $query->shortcode;
        if ($query->checkoutPath !== null) {
            $shortcodes = $source->shortcodesAtCheckout($query->checkoutPath);
            if (count($shortcodes) > 1) {
                return new SiteFound(null, $shortcodes);
            }
            $shortcode = $shortcodes[0] ?? null;
        }

        $site = $shortcode === null ? null : $source->siteByShortcode($shortcode);
        if ($site === null) {
            return new SiteFound(null, []);
        }

        return new SiteFound(SiteAnswer::of(
            $site['standard'],
            $site['shortcode'],
            $site['last_checked_utc'],
            $site['skipped_reason'],
            $site['report'],
            $site['php_current'],
            $site['test_php'],
        ), []);
    }
}
```

- [ ] **Step 7: Run the Unit suite.** Expected: PASS.

- [ ] **Step 8: Commit.**

```bash
git add classes/Inventory/Standards/SiteSource.php classes/Inventory/Standards/SiteFound.php classes/Inventory/Standards/SiteLookup.php classes/Inventory/Standards/Board.php Tests/Unit/StandardsSiteLookupTest.php
git commit -m "Add SiteLookup, shared by the API and site.sh" -m "The v1 handler turns a checkout or shortcode into a SiteAnswer inline. The next-step command needs the same answer over ssh, and two copies of the lookup would drift. Board sits behind a small interface so the lookup can be tested with made-up sites." -m "<attribution line>"
```

### Task 7: The v1 handler asks `SiteLookup`

**Files:**
- Modify: `wwwroot/api/v1/_standards.php` (everything from `$board = new ...` to the end of the file)

**Interfaces:**
- Consumes: `SiteLookup::find(SiteQuery $query, SiteSource $source): SiteFound`.
- The API's answers do not change by one byte: `{"site": null}`,
  `{"site": {...}}`, and 409 with
  `{"error": "More than one site uses that checkout; pass shortcode", "shortcodes": [...]}`.

No unit test: the Api suite points at production and is not part of the
gate. The lookup's behaviour is pinned by Task 6; this task is wiring.

- [ ] **Step 1: Replace the tail of the file** (keep everything up to and
  including the `try { $query = ... } catch` block) with:

```php
$found = \Inventory\Standards\SiteLookup::find($query, new \Inventory\Standards\Board($pdo));

if ($found->shortcodes !== []) {
    http_response_code(409);
    echo json_encode([
        'error' => 'More than one site uses that checkout; pass shortcode',
        'shortcodes' => $found->shortcodes,
    ]);
    exit;
}

echo json_encode(['site' => $found->site]);
```

- [ ] **Step 2: Check it.**

Run: `~/work/rob/standards-mcp/run-phpstan.sh ~/work/rob/mg.robnugen.com` Expected: exit 0
Run: `~/work/rob/standards-mcp/run-phpcs.sh ~/work/rob/mg.robnugen.com` Expected: exit 0
Run: `grep -c 'siteByShortcode\|shortcodesAtCheckout\|SiteAnswer::of' wwwroot/api/v1/_standards.php` Expected: `0`

- [ ] **Step 3: Commit.**

```bash
git add wwwroot/api/v1/_standards.php
git commit -m "Standards API: find the site via SiteLookup" -m "The handler's own copy of the lookup goes; it now gives what SiteLookup finds, the same call the next-step command will make. Its three answers are unchanged." -m "<attribution line>"
```

### Task 8: The last scan's step commit, and the answer's shape

**Files:**
- Modify: `classes/Inventory/Standards/Board.php`
- Create: `classes/Inventory/Standards/NextStepAnswer.php`
- Test: `Tests/Unit/StandardsBoardStepsCommitTest.php`, `Tests/Unit/StandardsNextStepAnswerTest.php`

**Interfaces:**
- Consumes: `SiteFound` (Task 6); the list `SiteStore::forScanner()` returns
  (each entry has a `shortcode` key; unchanged).
- Produces:
  - `Board::latestStepsCommit(): ?string`
  - `NextStepAnswer::of(SiteFound $found, array $scannerSites, ?string $stepsCommit): array`
    returning exactly `['site' => ..., 'shortcodes' => ..., 'facts' => ..., 'steps_commit' => ...]`
    in that key order.

- [ ] **Step 1: Write the failing test** `Tests/Unit/StandardsBoardStepsCommitTest.php`.
  This one `Board` method reads one table with plain SQL, so SQLite can stand
  in (as the other PDO tests in `Tests/Unit/` do):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\Board;

/**
 * Hermetic: which commit's step files mg last imported, from std_scans in SQLite.
 */
final class StandardsBoardStepsCommitTest extends Unit
{
    private const string OLDER = '1111111111111111111111111111111111111111';
    private const string NEWER = '2222222222222222222222222222222222222222';

    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE std_scans (
                std_scan_id INTEGER PRIMARY KEY,
                scanned_at_utc TEXT NOT NULL,
                steps_commit TEXT NOT NULL
            )'
        );
    }

    public function testNoScanYetIsNull(): void
    {
        $this->assertNull(new Board($this->pdo)->latestStepsCommit());
    }

    public function testItIsTheNewestScansWhateverOrderTheyWereImportedIn(): void
    {
        $this->pdo->exec(
            "INSERT INTO std_scans (std_scan_id, scanned_at_utc, steps_commit) VALUES
                (1, '2026-11-08 01:20:00.000000', '" . self::NEWER . "'),
                (2, '2026-11-07 01:20:00.000000', '" . self::OLDER . "')"
        );

        $this->assertSame(self::NEWER, new Board($this->pdo)->latestStepsCommit());
    }
}
```

- [ ] **Step 2: Write the failing test** `Tests/Unit/StandardsNextStepAnswerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\NextStepAnswer;
use Inventory\Standards\SiteFound;

/**
 * Hermetic: the one JSON object the next-step command prints. Sites are made up.
 */
final class StandardsNextStepAnswerTest extends Unit
{
    private const string SHA = '0123456789abcdef0123456789abcdef01234567';

    /**
     * One site as SiteStore::forScanner() lists it.
     *
     * @return array{shortcode: string, standard: string, checkout_path: ?string, server: ?array{ssh_alias: string, directory: string}, server_problem: ?string, php_current: ?string, test: ?array{domain: string, php_version: ?string, ssh_alias: ?string, directory: ?string}, upgrade_branch: ?string}
     */
    private function facts(string $shortcode): array
    {
        return [
            'shortcode' => $shortcode,
            'standard' => 'template',
            'checkout_path' => 'rob/example.test',
            'server' => ['ssh_alias' => 'example', 'directory' => 'example.test'],
            'server_problem' => null,
            'php_current' => '8.2',
            'test' => [
                'domain' => 'test.example.test',
                'php_version' => '8.5',
                'ssh_alias' => 'example',
                'directory' => 'test.example.test',
            ],
            'upgrade_branch' => 'feature/next',
        ];
    }

    private function found(string $shortcode): SiteFound
    {
        return new SiteFound([
            'shortcode' => $shortcode,
            'standard' => 'template',
            'standing' => ['mark' => 'check', 'text' => 'up to date'],
            'php_current' => '8.2',
            'test_php' => '8.5',
            'last_checked_utc' => '2026-11-07 03:12',
            'skipped_reason' => null,
            'open_steps' => [],
        ], []);
    }

    public function testAFoundSiteComesWithItsOwnFactsAndTheScansCommit(): void
    {
        $found = $this->found('example');

        $answer = NextStepAnswer::of($found, [$this->facts('mg'), $this->facts('example')], self::SHA);

        $this->assertSame(['site', 'shortcodes', 'facts', 'steps_commit'], array_keys($answer));
        $this->assertSame($found->site, $answer['site']);
        $this->assertSame([], $answer['shortcodes']);
        $this->assertSame($this->facts('example'), $answer['facts']);
        $this->assertSame(self::SHA, $answer['steps_commit']);
    }

    public function testNoSiteHasNoFactsButStillTheScansCommit(): void
    {
        $answer = NextStepAnswer::of(new SiteFound(null, []), [$this->facts('mg')], self::SHA);

        $this->assertNull($answer['site']);
        $this->assertSame([], $answer['shortcodes']);
        $this->assertNull($answer['facts']);
        $this->assertSame(self::SHA, $answer['steps_commit']);
    }

    public function testSeveralSitesAreNamedAndNoneIsPicked(): void
    {
        $answer = NextStepAnswer::of(
            new SiteFound(null, ['example', 'example-blog']),
            [$this->facts('example'), $this->facts('example-blog')],
            self::SHA,
        );

        $this->assertNull($answer['site']);
        $this->assertSame(['example', 'example-blog'], $answer['shortcodes']);
        $this->assertNull($answer['facts']);
    }

    public function testBeforeAnyScanTheCommitIsNull(): void
    {
        $answer = NextStepAnswer::of($this->found('mg'), [$this->facts('mg')], null);

        $this->assertNull($answer['steps_commit']);
        $this->assertSame($this->facts('mg'), $answer['facts']);
    }

    public function testItPrintsAsOneJsonObjectWithAnEmptyList(): void
    {
        $json = json_encode(
            NextStepAnswer::of(new SiteFound(null, []), [], null),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $this->assertSame('{"site":null,"shortcodes":[],"facts":null,"steps_commit":null}', $json);
    }
}
```

- [ ] **Step 3: Run the Unit suite.** Expected: FAIL, both new test classes
  (`Call to undefined method ...Board::latestStepsCommit()`,
  `Class "Inventory\Standards\NextStepAnswer" not found`).

- [ ] **Step 4: Add `Board::latestStepsCommit()`** after `siteByShortcode()`:

```php
    /**
     * The commit whose step files the newest scan was read from, whichever
     * sites that scan covered; null before any scan. A script on Lemur 13
     * reads a step's model from that commit, so it sees what mg imported.
     */
    public function latestStepsCommit(): ?string
    {
        $rows = $this->rows(
            'SELECT steps_commit FROM std_scans ORDER BY scanned_at_utc DESC, std_scan_id DESC LIMIT 1'
        );
        return $rows === [] ? null : RowValue::string($rows[0], 'steps_commit');
    }
```

- [ ] **Step 5: Run the Unit suite, then commit the Board method with its test.**
  Expected: `StandardsBoardStepsCommitTest` passes; `StandardsNextStepAnswerTest`
  still fails. Stage only these two files:

```bash
git add classes/Inventory/Standards/Board.php Tests/Unit/StandardsBoardStepsCommitTest.php
git commit -m "Board: answer the latest scan's steps commit" -m "A step's model is not stored in the database. The next-step script reads it from the step files at the commit mg last imported, so mg has to say which commit that was." -m "<attribution line>"
```

- [ ] **Step 6: Write `NextStepAnswer.php`.**

```php
<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The one JSON object `site_standards.php next-step` prints, for the
 * next-step script on Lemur 13:
 *
 *     site          what GET /standards/site gives (SiteAnswer), or null
 *     shortcodes    every site using the checkout, when there is more than
 *                   one (then site is null); otherwise []
 *     facts         that site's entry exactly as the `sites` command prints
 *                   it, or null when site is null
 *     steps_commit  the commit of the latest scan's step files, or null
 *                   before any scan
 *
 * facts holds what SiteAnswer keeps out (ssh aliases, directories, domains).
 * It goes over ssh to Lemur 13 only, never into the v1 API.
 *
 * @phpstan-import-type Answer from SiteAnswer
 */
final class NextStepAnswer
{
    /**
     * @template T of array{shortcode: string}
     * @param list<T> $scannerSites every site, as SiteStore::forScanner() lists them
     * @return array{site: ?Answer, shortcodes: list<string>, facts: T|null, steps_commit: ?string}
     */
    public static function of(SiteFound $found, array $scannerSites, ?string $stepsCommit): array
    {
        $facts = null;
        if ($found->site !== null) {
            foreach ($scannerSites as $site) {
                if ($site['shortcode'] === $found->site['shortcode']) {
                    $facts = $site;
                }
            }
        }

        return [
            'site' => $found->site,
            'shortcodes' => $found->shortcodes,
            'facts' => $facts,
            'steps_commit' => $stepsCommit,
        ];
    }
}
```

- [ ] **Step 7: Run the Unit suite.** Expected: PASS.

- [ ] **Step 8: Commit.**

```bash
git add classes/Inventory/Standards/NextStepAnswer.php Tests/Unit/StandardsNextStepAnswerTest.php
git commit -m "Add NextStepAnswer" -m "One object carries everything the next-step script asks mg for: the site's open steps as the API gives them, the scanner's facts about it so a check runs in the scanner's environment, and the commit to read step models from. Keeping the shape in one tested place fixes it for the script." -m "<attribution line>"
```

### Task 9: `SiteCommand next-step`

**Files:**
- Modify: `classes/Inventory/Standards/SiteCommand.php`, `tools/site_standards.php`, `tools/site-standards/site.sh` (comment only)
- Test: `Tests/Unit/StandardsSiteCommandTest.php`

**Interfaces:**
- Consumes: `SiteLookup::find` (Task 6), `Board::latestStepsCommit` and
  `NextStepAnswer::of` (Task 8), `SiteStore::forScanner()`, `SiteQuery::parse`,
  `Names::isShortcode`, `Names::isCheckoutPath`.
- Produces: `SiteCommand::run(array $args, SiteStore $store, Board $board): string`
  (a third parameter; `tools/site_standards.php` is its only caller) and the
  `next-step` command with the output and refusals in the table at the top of
  this part.

`SiteStore` and `Board` are final and all SQL, so `run()` has no unit test
(none of its commands has one). The three lines `next-step` adds to it only
join `SiteLookup`, `forScanner()`, `latestStepsCommit()` and `NextStepAnswer`,
each tested by itself; `parse()` is tested here.

The parse and the run go in one commit: a `next-step` that parses but has no
`case` in `run()` would fall through to `default`, which is `site-unlink`.

- [ ] **Step 1: Write the failing tests** in `StandardsSiteCommandTest`:

```php
    public function testNextStepTakesACheckout(): void
    {
        $parsed = SiteCommand::parse(['next-step', '--checkout=rob/example.test']);

        $this->assertSame('next-step', $parsed['command']);
        $this->assertSame(['checkout' => 'rob/example.test'], $parsed['options']);
    }

    public function testNextStepTakesAShortcode(): void
    {
        $parsed = SiteCommand::parse(['next-step', '--shortcode=mg']);

        $this->assertSame(['shortcode' => 'mg'], $parsed['options']);
    }

    public function testNextStepRefusesBoth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('next-step takes --checkout= or --shortcode=, not both.');
        SiteCommand::parse(['next-step', '--checkout=rob/example.test', '--shortcode=mg']);
    }

    public function testNextStepRefusesNeither(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('next-step needs --checkout= or --shortcode=.');
        SiteCommand::parse(['next-step']);
    }

    public function testNextStepCountsAnEmptyValueAsAbsent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('next-step needs --checkout= or --shortcode=.');
        SiteCommand::parse(['next-step', '--checkout=', '--shortcode=']);
    }

    public function testNextStepRefusesAnAbsoluteCheckout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--checkout= is relative to ~/work, like rob/example.com.');
        SiteCommand::parse(['next-step', '--checkout=/home/u/work/rob/example.test']);
    }

    public function testNextStepRefusesACheckoutThatClimbsOut(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--checkout= is relative to ~/work, like rob/example.com.');
        SiteCommand::parse(['next-step', '--checkout=rob/../etc']);
    }

    public function testNextStepRefusesWhatIsNotAShortcode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--shortcode= is not a shortcode.');
        SiteCommand::parse(['next-step', '--shortcode=Not OK']);
    }

    public function testNextStepTakesNoOtherOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('next-step does not take --domain.');
        SiteCommand::parse(['next-step', '--shortcode=mg', '--domain=example.test']);
    }
```

- [ ] **Step 2: Run the Unit suite.** Expected: FAIL, the nine new tests
  (`Unknown command: next-step. ...`).

- [ ] **Step 3: `SiteCommand::parse` learns the command.**

Add to `COMMANDS`, after `'test-candidates'`:

```php
        'next-step' => ['required' => [], 'optional' => ['checkout', 'shortcode']],
```

At the end of `parse()`, after the `foreach ($shape['required'] ...)` loop and
before the `return`:

```php
        if ($command === 'next-step') {
            self::siteQuery($options);
        }
```

Add the method after `projectId()`:

```php
    /**
     * Which site next-step is asked about: exactly one of --checkout= and
     * --shortcode=, an empty value counting as absent.
     *
     * @param array<string, string> $options
     */
    private static function siteQuery(array $options): SiteQuery
    {
        $checkout = $options['checkout'] ?? '';
        $shortcode = $options['shortcode'] ?? '';
        if ($checkout !== '' && $shortcode !== '') {
            throw new \InvalidArgumentException('next-step takes --checkout= or --shortcode=, not both.');
        }
        if ($checkout === '' && $shortcode === '') {
            throw new \InvalidArgumentException('next-step needs --checkout= or --shortcode=.');
        }
        if ($shortcode !== '' && !Names::isShortcode($shortcode)) {
            throw new \InvalidArgumentException('--shortcode= is not a shortcode.');
        }
        if ($checkout !== '' && !Names::isCheckoutPath($checkout)) {
            throw new \InvalidArgumentException('--checkout= is relative to ~/work, like rob/example.com.');
        }

        return SiteQuery::parse(['checkout_path' => $checkout, 'shortcode' => $shortcode]);
    }
```

- [ ] **Step 4: `SiteCommand::run` answers it.** The signature becomes:

```php
    public static function run(array $args, SiteStore $store, Board $board): string
```

Add the case after `'test-candidates'`:

```php
            case 'next-step':
                return json_encode(
                    NextStepAnswer::of(
                        SiteLookup::find(self::siteQuery($o), $board),
                        $store->forScanner(),
                        $board->latestStepsCommit(),
                    ),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                );
```

In the class docblock, add the command to the list after `test-candidates`:

```
 *     next-step   --checkout= | --shortcode=
```

and this paragraph after the "An option given with an empty value" line:

```
 * next-step is for tools/site-standards/next-step.sh. It prints one JSON
 * object (NextStepAnswer): the site's open steps as GET /standards/site
 * gives them, its entry from `sites`, and the commit of the latest scan's
 * step files. --checkout= is relative to ~/work. A checkout or shortcode no
 * site has is an answer with a null site, not a refusal.
```

- [ ] **Step 5: `tools/site_standards.php` hands it the Board.** The `try` body becomes:

```php
    // Read the arguments before connecting, so a typo needs no database.
    \Inventory\Standards\SiteCommand::parse($args);
    $pdo = \Database\Base::getPDO(new \Config());
    echo \Inventory\Standards\SiteCommand::run(
        $args,
        new \Inventory\Standards\SiteStore($pdo),
        new \Inventory\Standards\Board($pdo),
    ), "\n";
```

Add to the file's docblock examples:

```
 *     php tools/site_standards.php next-step --checkout=rob/example.test
```

- [ ] **Step 6: `tools/site-standards/site.sh`**, one more example line in its
  header comment, after the `sites` line:

```
#   tools/site-standards/site.sh next-step --checkout=rob/example.test
```

- [ ] **Step 7: Run the Unit suite, phpcs and PHPStan.** Expected: all exit 0.
  Also `bash -n tools/site-standards/site.sh` (no output).

- [ ] **Step 8: Commit.**

```bash
git add classes/Inventory/Standards/SiteCommand.php tools/site_standards.php tools/site-standards/site.sh Tests/Unit/StandardsSiteCommandTest.php
git commit -m "Add the next-step command to SiteCommand" -m "A script has no jikan MCP to call, and it already needs the scanner's facts over ssh, so one ssh call now answers everything /next-mg-step looks up. SiteCommand::run takes the Board as well, because the open steps come from it." -m "<attribution line>"
```

### Task 10: Close bubble B

- [ ] **Step 1: All gates**, exactly as Task 4 Step 1, with the same
  expectation for Rector (the two `Tests/Unit/Issues/` files and no other).
  Then `check_tests(repo="mg")` returns `status: pass`.

- [ ] **Step 2: `git status` is clean, `git branch --show-current` is `next-step-command`.**

- [ ] **Step 3: Close.**

```bash
git close-bubble --dry-run
```

Expected: it names `BEGIN next-step command over ssh`. Then:

```bash
git close-bubble "DONE next-step command over ssh"
```

Do not push. Tell Rob both bubbles are closed and stacked
(`next-step-script`, then `next-step-command`), and what is owed after he
deploys:

- [ ] **Step 4 (needs Rob's deploy; run only when he says it is deployed):**

```bash
tools/site-standards/site.sh next-step --shortcode=mg | python3 -I -m json.tool
```

Expected: an object with the four keys; `site.shortcode` is `mg`; `facts`
is the same object `tools/site-standards/site.sh sites` lists for `mg`;
`steps_commit` is 40 hex characters.

```bash
tools/site-standards/site.sh next-step; echo "exit $?"
```

Expected: `next-step needs --checkout= or --shortcode=.` and `exit 1`.

The jikan call `mg_api GET /standards/site?shortcode=mg` must give the same
`site` object as before the deploy.

---

### Open questions

1. **A Rob step in a track that names a model.** The spec says a
   `whose_move: rob` step "needs none and gets null". This plan resolves
   every step the same way (own line, else track's), so the three Rob steps
   under mlaphp carry `opus` in `StepDef` though nothing reads it. Forcing
   null for Rob's steps is one line in `StepTree::step()` and one changed
   test (`testRobsStepInATrackWithAModelIsAccepted`); say if you want it.
2. **Bubble A's closing words.** Its BEGIN commit is titled for the whole
   feature ("BEGIN script for the next-mg-step lookup") but the bubble holds
   only the step models. The plan closes it "DONE steps name their model";
   Rob may prefer his own words.
3. **Bubble B's branch.** The plan stacks a new branch `next-step-command`
   on A's closed tip. Staying on `next-step-script` works too, if the joined
   plan wants one branch for every part.

---

## Part 2: the script, the brief and the skill

Covers the spec's "Order of work" items 3, 4 and 5. Tasks 1 to 8 are one merge
bubble in mg (the script, its template, its tests, the `CLAUDE.md` paragraph).
Tasks 9 and 10 happen later, outside that bubble, after Rob has deployed mg and
run a scan: the live trial, then the new skill file in `~/.claude`.

### What Part 2 takes from Part 1

`tools/site-standards/site.sh next-step --checkout=<path under ~/work>` or
`--shortcode=<code>` runs over `ssh mg` and prints ONE JSON object:

    {"site": <SiteAnswer or null>, "shortcodes": [...], "facts": <the site's object from `site.sh sites`, or null>, "steps_commit": "<40 hex>" or null}

A refusal is mg's sentence on stderr and exit 1 (`tools/site_standards.php`
exits 1 on `\InvalidArgumentException`). ssh itself failing is exit 255. The
script tells the two apart by that exit code and by nothing else.

Shapes read from the code, which the tests copy:

- `site` (`classes/Inventory/Standards/SiteAnswer.php`): `shortcode`,
  `standard` (the standard's **slug**, from `Board::siteByShortcode`),
  `standing.mark`, `standing.text`, `php_current`, `test_php`,
  `last_checked_utc`, `skipped_reason` (null or the fixed phrase
  `SiteAnswer::SKIPPED`), `open_steps[]` of `slug`, `track` (the track's
  **title**, not its directory), `title`, `whose_move`, `detail`, `check`,
  `check_param`, `explanation`, `how_to`.
- `facts` (`SiteStore::forScanner`): `shortcode`, `standard`, `checkout_path`,
  `server` (`{ssh_alias, directory}` or null), `server_problem`,
  `php_current`, `test` (`{domain, php_version, ssh_alias, directory}` or
  null), `upgrade_branch`.

### Decisions made here (the spec left them to the builder, or the code forced them)

1. **`scan.py` is imported as it is; no refactor.** `scan.py` ends in
   `if __name__ == "__main__":`, and at import it only puts its own directory
   on `sys.path` and imports `memo` and `server_facts`. The existing tests
   already `import scan`. `next_step.py` does the same
   (`sys.path.insert(0, HERE)` then `import scan`, because `python3 -I` leaves
   the script's directory off the path) and uses `scan.git`,
   `scan.check_environment`, `scan.run_check`, `scan.php_targets`,
   `scan.frontmatter`, `scan.step_name`, `scan.CHECK_NAME`, and
   `scan.server_facts.head_and_branch` / `NoAnswer` / `SHA` / `BRANCH`. None of
   those calls `scan.stop()` (which exits the process). There is also a reason
   not to touch `scan.py`: `scan.checks_match()` lists it, so any edit to it
   stops every scan until Rob deploys. `next_step.py`, its template and its
   tests are not in that list, so this bubble never blocks a scan. Task 2 has a
   test that pins the names `next_step.py` relies on.
2. **Finding a step's file from mg's answer.** The answer gives the step's
   `slug` and the site's `standard` slug; `track` is a title and is not used
   for this. `StepTree` refuses a step name used twice inside one standard
   (`$stepNames`, per standard), so within `steps/<standard>/` exactly one
   file `<track dir>/<number>_<slug>.md` can match. The script lists
   `tools/site-standards/steps/<standard>/` at `steps_commit` with
   `git ls-tree -r --name-only`, keeps the paths of the form
   `<track>/<file>.md` whose stem does not start with `_` and for which
   `scan.step_name(stem) == slug` (the same split as `Names::stepFile`), reads
   that file with `git show <commit>:<path>` and takes
   `scan.frontmatter(content).get("model")`; when the step has none, the same
   for `<track>/_track.md`. `scan.frontmatter` reads flat `key: value` lines
   between the two `---` lines and strips one pair of double quotes, as
   `StepTree::frontmatter` does. Python does not judge the file beyond that.
3. **Backticks in the sentences are literal.** The skill writes
   `` `<shortcode>` is up to date `` and `` **`<title>`** ``; the script prints
   the backticks and asterisks and replaces only the `<name>` part.
4. **Where the skill has no fixed sentence** (which shortcode, the skipped
   scan, the "which step" line, the second-line question, the two one-line
   notes, a check that could not tell), Task 2 to Task 4 fix one and the tests
   pin it. These are new wording; every sentence the skill does quote is
   copied from it.
5. **The session's model reaches the script as `--session-model=<name>`.** The
   spec wants `say` to end with one line when the session runs on a cheaper
   model than a `do` step names, but a script cannot see which model called
   it. The skill passes the flag; without it the line is never added.
6. **The brief template has three parts**, split by two marker lines: the step
   itself, the rules (shared by a subagent and by the session doing a `do` or
   a taste step), and what applies only to a subagent (the memory files,
   "needs Rob", timestamps, the three reports). `brief` is all three; `rules`
   is the middle one. A session can ask Rob, so it must not be told to return
   "needs Rob".
7. **`--confirm` counts only `.php` files** for `@phpstan-ignore` lines and for
   tests newly skipped. Otherwise this very bubble (whose Python tests and
   template contain those words) could never be `clean`.
8. **`--confirm` asks mg again** for the site and its first open step: no scan
   has run since the lookup, so it is the same step, and the check needs the
   same facts. A stop there (mg unreachable and so on) is returned with
   `"clean": false`.
9. **The live trial comes before the skill is switched.** The spec says both
   "the new skill file, then a live run" and "before the skill is switched
   over, try one `sonnet` step live". Task 9 does the trial by hand, following
   the new skill's steps, while the old skill is still installed; Task 10
   installs the new one.

### Rules for every task

- Never commit on master, never push, never check out or merge into a trunk.
- Commits: subject at most 50 characters, imperative, capitalised, no full
  stop; the body says why. Always `git commit -m "Subject" -m "Body"`, never a
  heredoc. Every commit message ends with one more `-m "<attribution line>"`.
- One layer per commit: the version bump, the template text, the script with
  its tests, the docs.
- The repo is public. Tests, comments and the template use `mg`, `demo`,
  `*.example` hosts and made-up aliases only. The present skill names two real
  sites in examples; neither name appears anywhere in this bubble.
- `.gitignore` ignores `php*`. `tools/site-standards/**` is exempt, but an
  ignored file leaves `git status` clean, so after adding files run
  `git ls-files tools/site-standards | grep -c next` and compare with what was
  created.
- Nothing the script prints uses warning or alarm wording.
- The script never writes to any repo and never changes anything on mg. Its
  git calls go through `scan.git` (`--no-optional-locks`); the wrapper starts
  Python with `-B` so no bytecode is written either. `--confirm` runs gates
  only through the standards-mcp runner scripts, which mount the repo
  read-only.
- Python: standard library only, started with `python3 -I`, no f-string
  features newer than Lemur 13's Python.

### Files

| File | What it is |
|---|---|
| `version.php` (modify) | BEGIN commit only |
| `tools/site-standards/next-step.sh` (create, executable) | thin wrapper, like `scan.sh` |
| `tools/site-standards/next_step.py` (create) | the lookup and `--confirm` |
| `tools/site-standards/next_step_brief.md` (create) | the brief and rules template |
| `tools/site-standards/tests/next_step_case.py` (create) | the shared test fixture (not a test file itself) |
| `tools/site-standards/tests/test_next_step_stops.py` (create) | path, mg, and the stops before an agent step |
| `tools/site-standards/tests/test_next_step_agent.py` (create) | the step line, the second line, the check here |
| `tools/site-standards/tests/test_next_step_model.py` (create) | model, template, dispatch, do |
| `tools/site-standards/tests/test_next_step_confirm.py` (create) | `--confirm` against a scratch repo |
| `CLAUDE.md` (modify) | one paragraph under "Site standards" |
| `~/.claude/skills/next-mg-step/SKILL.md` (replace; another repo; Task 10) | the short dispatcher |
| `~/.claude/projects/-home-thunderrabbit-work-rob-mg-robnugen-com/memory/project_next_mg_step_806.md` (modify; Task 10) | what the trial found |

### Review focus for Part 2

Inputs the spec implies but does not list; each has a test in the task named.

1. A how-to or title that itself contains `{{title}}` or `$` must come out of
   the template unchanged (no second pass). Task 6.
2. `steps_commit` that is null, not 40 hex digits, or a commit the mg checkout
   does not have must give the "no model" sentence, never a git error and
   never a guess. Task 6.
3. A site whose `facts.server` is null (mg could not choose a server) must
   still get its step; the reason is quoted. Task 3.
4. A detached HEAD in the site's checkout: the lookup still works and never
   asks about `upgrade_branch`; `--confirm` says it is not on a branch and is
   not clean. Tasks 3 and 7.
5. mg printing something that is not the JSON object (an old deployment, a PHP
   notice) is "mg refused the lookup", not a Python traceback. Task 2.

---

### Task 1: BEGIN commit

**Files:**
- Modify: `version.php`

- [ ] **Step 1: Confirm where you are.**

Run: `git -C ~/work/rob/mg.robnugen.com branch --show-current && git -C ~/work/rob/mg.robnugen.com status --short && grep SEMVER ~/work/rob/mg.robnugen.com/version.php`
Expected: branch `next-step-command` at bubble B's closed tip, no output from
status, and the SEMVER Part 1 left (expected `0.91.0`). Then
`git checkout -b next-step-lookup`. If the tip is an open bubble that is
not yours, or the tree is not clean, stop and ask Rob.

- [ ] **Step 2: Raise the version, and nothing else.** In `version.php` set
`SEMVER` to the next minor after whatever Part 1 left (expected `"0.92.0"`)
and `SENTIMENTAL_VERSION` to `"BEGIN next-step script and its brief"`.

- [ ] **Step 3: Commit.**

```bash
git add version.php
git commit -m "BEGIN next-step script and its brief" -m "Opens the bubble for tools/site-standards/next-step.sh: the lookup that /next-mg-step does by prose today becomes a script, so the same answer from mg gives the same words every time." -m "<attribution line>"
```

Expected: the pre-commit hook runs PHPStan (a PHP file is staged) and passes.

---

### Task 2: The wrapper, asking mg, and the stops before any step

**Files:**
- Create: `tools/site-standards/next-step.sh`, `tools/site-standards/next_step.py`
- Create: `tools/site-standards/tests/next_step_case.py`, `tools/site-standards/tests/test_next_step_stops.py`

**Interfaces:**
- Consumes: `site.sh next-step` (above); `scan.git(repo, *args) -> (code, stdout)`.
- Produces (later tasks build on these exact names):
  - `next_step.HERE` (directory of `next_step.py`; tests replace it), `next_step.BRIEF` (path of the template, fixed at import from the real directory, never replaced).
  - `class Stop(Exception)` with `.answer` (the dict to print); `class Usage(Exception)`.
  - `Options` (a `collections.namedtuple`): `shortcode`, `leave_upgrade`, `confirm`, `base`, `session_model`.
  - `parse_arguments(argv) -> Options` (raises `Usage`).
  - `checkout(cwd) -> (top, relative)` (raises `Stop`).
  - `ask_mg(options, relative) -> dict` (raises `Stop`).
  - `site_or_stop(reply) -> (site, facts, steps_commit)` (raises `Stop` for: several shortcodes, no site, nothing open, nobody can reach it).
  - `lookup(options, cwd) -> dict`, `answer(argv, cwd=None) -> dict`, `main(argv) -> int`.
  - `say_stop(text, **more) -> Stop` building `{"outcome": "say", "say": text, **more}`.
  - Test fixture `next_step_case.NextStepCase` (below).

- [ ] **Step 1: Write the fixture** `tools/site-standards/tests/next_step_case.py`.
It is not named `test_*`, so discovery does not run it. Every later test file
starts with the same four lines the fixture does and then
`from next_step_case import NextStepCase, GIT, write`.

```python
"""What every next_step test needs: a scratch mg checkout, a scratch site
checkout under a scratch ~/work, and stand-ins for `site.sh` and ssh.
Site names, hosts and paths are made up."""

import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, ".."))
sys.path.insert(0, HERE_TESTS)
import next_step  # noqa: E402

GIT = ["git", "-c", "user.name=t", "-c", "user.email=t@example.invalid"]
TRACK = "---\nname: Track\nposition: 10\nmodel: sonnet\n---\n"
STEP = "---\ntitle: A step\nstatus: proven\nwhose_move: agent\ncheck: echo\n---\nWhy.\n\n## How to do it\n\nDo it.\n"


def write(path, text, executable=False):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text)
    if executable:
        os.chmod(path, os.stat(path).st_mode | stat.S_IXUSR)


class NextStepCase(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = root = tmp.name

        # A scratch mg checkout: next_step.HERE points into it, so the checks
        # it runs and the step files it reads with git are the test's own.
        self.mg = os.path.join(root, "mg")
        self.here = os.path.join(self.mg, "tools", "site-standards")
        os.makedirs(os.path.join(self.here, "checks"))
        subprocess.run([*GIT, "init", "-q", "-b", "topic", self.mg], check=True)
        self.steps = os.path.join(self.here, "steps", "std", "track")
        write(os.path.join(self.here, "steps", "std", "_standard.md"), "---\nname: Std\n---\n")
        write(os.path.join(self.steps, "_track.md"), TRACK)
        write(os.path.join(self.steps, "010_a_step.md"), STEP)
        self.steps_commit = self.commit_all(self.mg, "steps")

        # A scratch site checkout under a scratch ~/work.
        self.work = os.path.join(root, "work")
        self.repo = os.path.join(self.work, "rob", "demo")
        os.makedirs(self.repo)
        subprocess.run([*GIT, "init", "-q", "-b", "master", self.repo], check=True)
        self.first = self.commit(self.repo, "first")
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "topic"], check=True)
        self.second = self.commit(self.repo, "second")

        # `site.sh`: logs its arguments; prints mg-answer, or fails as told.
        self.site_log = os.path.join(root, "site.log")
        site = os.path.join(root, "fake-site.sh")
        write(site, "#!/usr/bin/env bash\n"
                    f"printf '%s\\n' \"$*\" >> '{self.site_log}'\n"
                    f"if [ -f '{root}/mg-exit' ]; then cat '{root}/mg-stderr' >&2; exit \"$(cat '{root}/mg-exit')\"; fi\n"
                    f"cat '{root}/mg-answer'\n", executable=True)

        # ssh: answers by host; a host with no file cannot be reached.
        self.hosts = os.path.join(root, "hosts")
        os.makedirs(self.hosts)
        ssh = os.path.join(root, "fake-ssh.sh")
        write(ssh, "#!/usr/bin/env bash\n"
                   f"[ -f '{self.hosts}'/\"$1\" ] || {{ echo 'no route to host' >&2; exit 255; }}\n"
                   f"cat '{self.hosts}'/\"$1\"\n", executable=True)

        # standards-mcp: gates are written by a test that wants them.
        self.mcp = os.path.join(root, "standards-mcp")
        os.makedirs(self.mcp)
        home = os.path.join(root, "home")   # so the real repos.yaml is never read
        os.makedirs(home)

        patches = {"WORK_ROOT": self.work, "SCAN_SITE_CMD": site, "SCAN_SSH": ssh,
                   "STANDARDS_MCP": self.mcp, "HOME": home}
        saved = {name: os.environ.get(name) for name in patches}
        os.environ.update(patches)
        self.addCleanup(self.restore, saved)
        saved_here = next_step.HERE
        next_step.HERE = self.here
        self.addCleanup(setattr, next_step, "HERE", saved_here)

    @staticmethod
    def restore(saved):
        for name, value in saved.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value

    @staticmethod
    def commit(repo, message):
        subprocess.run([*GIT, "-C", repo, "commit", "-q", "--allow-empty", "-m", message], check=True)
        return subprocess.run([*GIT, "-C", repo, "rev-parse", "HEAD"], check=True,
                              capture_output=True, text=True).stdout.strip()

    def commit_all(self, repo, message):
        subprocess.run([*GIT, "-C", repo, "add", "-A"], check=True)
        return self.commit(repo, message)

    # ---- what mg says -------------------------------------------------
    @staticmethod
    def step(**over):
        base = {"slug": "a_step", "track": "Track", "title": "A step", "whose_move": "agent",
                "detail": "not yet", "check": "echo", "check_param": None,
                "explanation": "Why.", "how_to": "Do it."}
        return dict(base, **over)

    @staticmethod
    def site(steps, **over):
        base = {"shortcode": "demo", "standard": "std", "standing": {"mark": "info", "text": "1 step to go"},
                "php_current": "8.4", "test_php": None, "last_checked_utc": "2026-11-20 01:02:03",
                "skipped_reason": None, "open_steps": steps}
        return dict(base, **over)

    @staticmethod
    def facts(**over):
        base = {"shortcode": "demo", "standard": "std", "checkout_path": "rob/demo",
                "server": {"ssh_alias": "prod", "directory": "demo.example"}, "server_problem": None,
                "php_current": "8.4", "test": None, "upgrade_branch": None}
        return dict(base, **over)

    def mg_says(self, site, facts=None, shortcodes=(), steps_commit="scanned"):
        """What `site.sh next-step` prints. facts defaults to the usual ones when there is a site."""
        if site is not None and facts is None:
            facts = self.facts()
        commit = self.steps_commit if steps_commit == "scanned" else steps_commit
        write(os.path.join(self.root, "mg-answer"),
              json.dumps({"site": site, "shortcodes": list(shortcodes), "facts": facts, "steps_commit": commit}))

    def mg_fails(self, code, stderr):
        write(os.path.join(self.root, "mg-stderr"), stderr)
        write(os.path.join(self.root, "mg-exit"), str(code))

    def site_asked(self):
        with open(self.site_log, encoding="utf-8") as handle:
            return handle.read().splitlines()

    # ---- the server, the checks, the gates ----------------------------
    def server_runs(self, commit, host="prod"):
        write(os.path.join(self.hosts, host), commit + "\n")

    def check(self, name, exit_code, line, body=""):
        write(os.path.join(self.here, "checks", name + ".sh"),
              f"#!/usr/bin/env bash\n{body}\necho \"{line}\"\nexit {exit_code}\n", executable=True)

    def gate(self, name, exit_code=0, out="ran"):
        write(os.path.join(self.mcp, name),
              f"#!/usr/bin/env bash\necho \"$1\" >> '{self.root}/gates.log'\necho \"{out}\"\nexit {exit_code}\n",
              executable=True)

    def run_it(self, *argv, cwd=None):
        return next_step.answer(list(argv), cwd=cwd or self.repo)
```

- [ ] **Step 2: Write the failing tests** `tools/site-standards/tests/test_next_step_stops.py`.
Docstring as the other test files have ("run with `python3 -I -m unittest
discover -s tools/site-standards/tests` from the mg checkout"). Class
`NextStepStopsTest(NextStepCase)`:

```python
    def test_scan_still_has_what_next_step_borrows(self):
        import scan
        for name in ("git", "check_environment", "run_check", "php_targets", "frontmatter",
                     "step_name", "CHECK_NAME", "server_facts"):
            self.assertTrue(hasattr(scan, name), name)

    def test_a_directory_outside_work_stops(self):
        outside = os.path.join(self.root, "elsewhere")
        os.makedirs(outside)
        subprocess.run([*GIT, "init", "-q", outside], check=True)
        self.assertEqual(self.run_it(cwd=outside), {
            "outcome": "say",
            "say": "This repo isn't under ~/work, so it can't be on mg's standards page."})

    def test_a_directory_that_is_no_repo_stops_the_same_way(self):
        plain = os.path.join(self.work, "plain")
        os.makedirs(plain)
        self.assertEqual(self.run_it(cwd=plain)["say"],
                         "This repo isn't under ~/work, so it can't be on mg's standards page.")

    def test_mg_is_asked_by_checkout_path_from_a_subdirectory(self):
        self.mg_says(None)
        sub = os.path.join(self.repo, "deep", "er")
        os.makedirs(sub)
        self.run_it(cwd=sub)
        self.assertEqual(self.site_asked(), ["next-step --checkout=rob/demo"])

    def test_a_shortcode_is_passed_instead_of_the_path(self):
        self.mg_says(None)
        self.run_it("--shortcode=demo-two")
        self.assertEqual(self.site_asked(), ["next-step --shortcode=demo-two"])

    def test_ssh_failing_stops_with_its_last_line(self):
        self.mg_fails(255, "Warning: something\nssh: connect to host mg port 22: Connection refused\n")
        self.assertEqual(self.run_it(), {
            "outcome": "say",
            "say": "I can't reach mg from here (`ssh: connect to host mg port 22: Connection refused`), "
                   "so /next-mg-step can't look up the step."})

    def test_mg_refusing_stops_with_its_sentence(self):
        self.mg_fails(1, "Unknown command: next-step. Use one of: sites, site-add.\n")
        self.assertEqual(self.run_it()["say"],
                         "mg refused the lookup: `Unknown command: next-step. Use one of: sites, site-add.`")

    def test_an_answer_that_is_not_the_object_is_a_refusal(self):
        for text in ("PHP Notice: something\n", "[]", '{"site": null}'):
            with self.subTest(text=text):
                write(os.path.join(self.root, "mg-answer"), text)
                self.assertEqual(self.run_it()["say"],
                                 "mg refused the lookup: `its answer was not the one this script reads`")

    def test_several_sites_on_one_checkout_is_a_question(self):
        self.mg_says(None, shortcodes=["demo", "demo-two"])
        self.assertEqual(self.run_it(), {
            "outcome": "ask", "say": "",
            "ask": "More than one site uses this checkout: `demo`, `demo-two`. Which shortcode is meant?"})

    def test_no_site_stops(self):
        self.mg_says(None)
        self.assertEqual(self.run_it(), {
            "outcome": "say",
            "say": "This repo isn't on mg's standards page, so /next-mg-step has nothing for it."})

    def test_nothing_open_stops(self):
        self.mg_says(self.site([]))
        self.assertEqual(self.run_it(), {"outcome": "say", "say": "`demo` is up to date on mg's standards page."})

    def test_nobody_can_reach_it_lists_robs_steps_only(self):
        steps = [self.step(slug="one", title="Enter the domain", whose_move="rob", how_to="Type it in."),
                 self.step(slug="two", title="An agent step"),
                 self.step(slug="three", title="Prove the login", whose_move="rob", how_to="Run the sweep.")]
        self.mg_says(self.site(steps, standing={"mark": "face", "text": "unreachable"}))
        self.assertEqual(self.run_it(), {
            "outcome": "say",
            "say": "Nobody can reach `demo` yet, so these are yours first:\n\n"
                   "**`Enter the domain`**\n\nType it in.\n\n"
                   "**`Prove the login`**\n\nRun the sweep."})

    def test_unknown_options_are_a_usage_error_not_an_answer(self):
        for argv in (["--nope"], ["--shortcode=Not A Code"], ["--confirm"], ["--base=abc1234"],
                     ["--confirm", "--base=zzz"], ["--session-model=gpt"], ["stray"]):
            with self.subTest(argv=argv):
                with self.assertRaises(next_step.Usage):
                    next_step.answer(argv, cwd=self.repo)
```

and one test of `main` itself: with `mg_says(None)` and `os.chdir` not used,
call `next_step.main([])` with `cwd` patched through
`unittest.mock.patch("os.getcwd", return_value=self.repo)` and stdout
redirected; assert it returns 0 and that `json.loads` of stdout equals the
"no site" answer. A second: `next_step.main(["--nope"])` returns 2, prints
nothing on stdout, and stderr starts with
`Usage: next-step.sh [--shortcode=<code>] [--leave-upgrade] [--session-model=<model>] [--confirm --base=<commit>]`.

- [ ] **Step 3: Run them and see them fail.**

Run: `cd ~/work/rob/mg.robnugen.com && python3 -I -m unittest discover -s tools/site-standards/tests`
Expected: errors, `ModuleNotFoundError: No module named 'next_step'`.

- [ ] **Step 4: Write `tools/site-standards/next-step.sh`** (mode 755):

```bash
#!/usr/bin/env bash
# Look up this checkout's first open step on mg's standards page, for the
# /next-mg-step skill. Run it from inside a site's checkout.
#
#   tools/site-standards/next-step.sh                       which step, and who does it
#   tools/site-standards/next-step.sh --shortcode=example   when a checkout holds several sites
#   tools/site-standards/next-step.sh --leave-upgrade       Rob said no to setting upgrade_branch
#   tools/site-standards/next-step.sh --confirm --base=<commit>   after the work: facts, and "clean"
#
# Prints one JSON object and exits 0 whenever it could decide. Never writes
# to any repo and never changes anything on mg. The logic is in next_step.py.
set -euo pipefail
exec python3 -I -B "$(dirname "${BASH_SOURCE[0]}")/next_step.py" "$@"
```

- [ ] **Step 5: Write `next_step.py` up to these stops.** Module docstring in
`scan.py`'s voice: what it does, and what it deliberately does not (never
writes, never runs `site-set`, never runs a safe suite, never guesses a
model). Then, with these exact constants (later tasks add to the list):

```python
NOT_UNDER_WORK = "This repo isn't under ~/work, so it can't be on mg's standards page."
UNREACHABLE = "I can't reach mg from here (`{error}`), so /next-mg-step can't look up the step."
REFUSED = "mg refused the lookup: `{sentence}`"
NOT_THE_ANSWER = "its answer was not the one this script reads"
WHICH_SHORTCODE = "More than one site uses this checkout: {codes}. Which shortcode is meant?"
NOT_ON_PAGE = "This repo isn't on mg's standards page, so /next-mg-step has nothing for it."
UP_TO_DATE = "`{shortcode}` is up to date on mg's standards page."
NOBODY = "Nobody can reach `{shortcode}` yet, so these are yours first:"
USAGE = ("Usage: next-step.sh [--shortcode=<code>] [--leave-upgrade] [--session-model=<model>] "
         "[--confirm --base=<commit>]")
MODELS = ("haiku", "sonnet", "opus", "fable")   # cheapest first; mg's StepModel is the judge of the list
SHORTCODE = re.compile(r"\A[a-z0-9]+(?:-[a-z0-9]+)*\Z")   # Names::SHORTCODE, at most 24 characters
MG_TIMEOUT = 60
```

  - `parse_arguments`: `--shortcode=` must match `SHORTCODE` and be at most 24
    long; `--base=` must match `[0-9a-f]{7,40}`; `--confirm` and `--base=`
    come together or not at all; `--session-model=` must be in `MODELS`;
    anything else raises `Usage(USAGE)`.
  - `checkout(cwd)`: `scan.git(cwd, "rev-parse", "--show-toplevel")`; the work
    root is `os.environ.get("WORK_ROOT", os.path.expanduser("~/work"))`, both
    compared after `os.path.realpath`; not a repo, or not strictly inside the
    root, raises `say_stop(NOT_UNDER_WORK)`.
  - `ask_mg`: the command is
    `os.environ.get("SCAN_SITE_CMD", shlex.quote(os.path.join(HERE, "site.sh")))`
    plus ` next-step ` plus `shlex.quote("--shortcode=" + code)` when
    `options.shortcode` is set, else `shlex.quote("--checkout=" + relative)`;
    run with `["bash", "-c", command]`, `stdin=subprocess.DEVNULL`,
    `timeout=MG_TIMEOUT`, exactly as `scan.py` runs `site.sh sites`. Exit 255,
    or the timeout, is `UNREACHABLE` with the last non-empty stderr line (or
    `no answer`); any other non-zero exit is `REFUSED` with the last non-empty
    stderr line (or `no answer`). Exit 0 whose stdout is not a JSON object holding all four
    keys (`site`, `shortcodes`, `facts`, `steps_commit`), or whose `site` is
    set while `facts` is not an object, is `REFUSED` with `NOT_THE_ANSWER`.
    Printable characters only in a quoted line, as `scan.run_check` does.
  - `site_or_stop`, in the skill's order: more than one shortcode is
    `Stop({"outcome": "ask", "say": "", "ask": WHICH_SHORTCODE})` with the
    codes as `` `a`, `b` ``; `site` null is `NOT_ON_PAGE`; empty `open_steps`
    is `UP_TO_DATE`; `standing.mark == "face"` is `NOBODY` followed, for each
    open step whose `whose_move` is `rob`, by a blank line, `` **`<title>`** ``,
    a blank line and its `how_to`.
  - `answer(argv, cwd=None)`: parse, then `lookup` (or `confirm`, Task 7),
    catching `Stop` and returning its `.answer`. `Usage` is not caught here.
  - `main(argv)`: prints `json.dumps(answer(argv, os.getcwd()))` and returns
    0; on `Usage` prints the message on stderr and returns 2. Any other
    exception is the script breaking: let it raise (non-zero exit, traceback
    on stderr).
  - For this task `lookup` ends, after `site_or_stop`, with
    `raise NotImplementedError` (replaced in Task 3).

- [ ] **Step 6: Run the tests.**

Run: `python3 -I -m unittest discover -s tools/site-standards/tests`
Expected: `OK`, with the old tests still passing.

- [ ] **Step 7: Check git sees the new files, then commit.**

Run: `git status --short tools/site-standards && git ls-files --others --exclude-standard tools/site-standards`
Expected: the four new files listed by both (none hidden by `php*`).

```bash
git add tools/site-standards/next-step.sh tools/site-standards/next_step.py tools/site-standards/tests/next_step_case.py tools/site-standards/tests/test_next_step_stops.py
git commit -m "Add next-step.sh: ask mg, and the first stops" -m "The repo's path, the question to mg over ssh and the stops that need no step (not under ~/work, mg unreachable or refusing, several sites, no site, up to date, nobody can reach it) are now a script whose sentences the tests pin, in place of prose a model re-reads each run." -m "<attribution line>"
```

---

### Task 3: Rob's step, the agent's step line, and the second line

**Files:**
- Modify: `tools/site-standards/next_step.py`
- Create: `tools/site-standards/tests/test_next_step_agent.py`
- Modify: `tools/site-standards/tests/test_next_step_stops.py` (Rob's step)

**Interfaces:**
- Consumes: Task 2; `scan.server_facts.head_and_branch(alias, directory) -> (commit, branch or None)`, raising `NoAnswer`.
- Produces:
  - `step_line(site, step) -> str`
  - `second_line(site, facts, top, options) -> (notes: list[str], ask: str or None, server_commit, server_branch)`
  - `local_head(top) -> (commit, branch or None)`
  - `lookup` now ends, for an agent step, by calling `check_here` (Task 4); until Task 4 it returns `{"outcome": "do", "say": ...}` as a placeholder the Task 3 tests do not assert on (they assert on `say` and `ask` only).

- [ ] **Step 1: Failing tests.** In `test_next_step_stops.py`:

```python
    def test_robs_step_gives_title_detail_and_how_to(self):
        self.mg_says(self.site([self.step(whose_move="rob", title="Switch the panel", detail="still on 8.3",
                                          how_to="Open the panel.\nPick 8.5.")]))
        got = self.run_it()
        self.assertEqual(got["outcome"], "say")
        self.assertEqual(got["say"], "`demo`'s next step is yours: **`Switch the panel`** (`still on 8.3`).\n\n"
                                     "Open the panel.\nPick 8.5.")
        self.assertNotIn("after", got)

    def test_a_skipped_scan_is_said_first(self):
        self.mg_says(self.site([self.step(whose_move="rob")],
                               skipped_reason="the latest scan skipped this site; the standards page says why"))
        self.assertTrue(self.run_it()["say"].startswith(
            "These steps come from the scan of `2026-11-20 01:02:03` UTC: "
            "the latest scan skipped this site; the standards page says why\n\n`demo`'s next step is yours"))
```

In `test_next_step_agent.py`, class `NextStepAgentTest(NextStepCase)`. Every
test here uses a check named `echo` that exits 1 (`self.check("echo", 1, "not
yet")` in `setUp`), so the run reaches the end:

```python
    def first_line(self, **site):
        self.mg_says(self.site([self.step()], **site))
        self.server_runs(self.first)
        return self.run_it()["say"].split("\n")[0]

    def test_the_step_line_with_the_served_php(self):
        self.assertEqual(self.first_line(),
                         "`demo`, track `Track`, **`A step`** (`not yet`), served on PHP `8.4`.")

    def test_the_step_line_with_a_test_domains_php(self):
        self.assertEqual(self.first_line(test_php="8.5"),
                         "`demo`, track `Track`, **`A step`** (`not yet`), served on PHP `8.4`, "
                         "test domain on PHP `8.5`.")

    def test_the_step_line_with_no_sweep(self):
        self.assertEqual(self.first_line(php_current=None, test_php="8.5"),
                         "`demo`, track `Track`, **`A step`** (`not yet`); mg has no sweep for its PHP yet.")

    def test_one_line_site_gets_no_note(self):
        self.mg_says(self.site([self.step()]))
        self.server_runs(self.first)          # an ancestor of HEAD
        got = self.run_it()
        self.assertNotEqual(got["outcome"], "ask")
        self.assertEqual(len(got["say"].split("\n")), 1)

    def test_a_test_domain_and_no_upgrade_branch_gets_the_deploy_note(self):
        test = {"domain": "demo-test.example", "php_version": "8.5", "ssh_alias": "stage", "directory": "t"}
        self.mg_says(self.site([self.step()]), self.facts(test=test))
        self.server_runs(self.second)         # HEAD itself
        self.assertIn("mg will scan `demo` once this commit is deployed to `demo-test.example`; "
                      "production can wait.", self.run_it()["say"].split("\n"))

    def test_an_upgrade_branch_on_a_one_line_site_is_mentioned_and_left(self):
        self.mg_says(self.site([self.step()]), self.facts(upgrade_branch="green"))
        self.server_runs(self.first)
        got = self.run_it()
        self.assertNotEqual(got["outcome"], "ask")
        self.assertIn("`demo` has `upgrade_branch` set to `green` on mg; clearing it "
                      "(`site-set --shortcode=demo --upgrade=`) is your call.", got["say"].split("\n"))

    def test_a_second_line_asks_before_setting_the_upgrade_branch(self):
        self.mg_says(self.site([self.step()]))
        self.server_runs("c" * 40)            # a commit this branch lacks
        got = self.run_it()
        self.assertEqual(got["outcome"], "ask")
        self.assertEqual(got["ask"],
                         "The server runs commits this branch lacks, so `topic` is a second line being "
                         "prepared beside the live one. May I run "
                         "`~/work/rob/mg.robnugen.com/tools/site-standards/site.sh site-set --shortcode=demo "
                         "--upgrade=topic`, so that mg scans `topic`'s tip for `demo`?")
        self.assertTrue(got["say"].startswith("`demo`, track `Track`"))
        self.assertEqual(sorted(got), ["ask", "outcome", "say"])

    def test_a_server_on_a_side_branch_is_also_a_second_line(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "side", self.first], check=True)
        side = self.commit(self.repo, "a fix on the server's line only")
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "topic"], check=True)
        self.mg_says(self.site([self.step()]))
        self.server_runs(side)
        self.assertEqual(self.run_it()["outcome"], "ask")

    def test_leave_upgrade_skips_the_question_and_goes_on(self):
        self.mg_says(self.site([self.step()]))
        self.server_runs("c" * 40)
        got = self.run_it("--leave-upgrade")
        self.assertNotEqual(got["outcome"], "ask")
        self.assertNotIn("mg will scan", got["say"])

    def test_a_second_line_already_named_is_said_and_not_asked(self):
        self.mg_says(self.site([self.step()]), self.facts(upgrade_branch="topic"))
        self.server_runs("c" * 40)
        got = self.run_it()
        self.assertNotEqual(got["outcome"], "ask")
        self.assertIn("mg will scan `topic`'s tip for `demo`.", got["say"].split("\n"))

    def test_a_detached_head_is_never_asked_about(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "--detach"], check=True)
        self.mg_says(self.site([self.step()]))
        self.server_runs("c" * 40)
        self.assertNotEqual(self.run_it()["outcome"], "ask")

    def test_a_server_that_does_not_answer_is_quoted_and_the_run_goes_on(self):
        self.mg_says(self.site([self.step()]))      # no file for host "prod"
        got = self.run_it()
        self.assertNotEqual(got["outcome"], "ask")
        self.assertIn("Its server did not say what it runs "
                      "(`could not ask its server which commit it runs: no route to host`).",
                      got["say"].split("\n"))

    def test_a_site_mg_could_choose_no_server_for_goes_on_too(self):
        self.mg_says(self.site([self.step()]),
                     self.facts(server=None, server_problem="its domains have different checkouts"))
        self.assertIn("Its server did not say what it runs (`its domains have different checkouts`).",
                      self.run_it()["say"].split("\n"))
```

- [ ] **Step 2: Run, see them fail** (`NotImplementedError`).

- [ ] **Step 3: Implement.** New constants:

```python
FROM_SCAN = "These steps come from the scan of `{last_checked_utc}` UTC: {skipped_reason}"
YOURS = "`{shortcode}`'s next step is yours: **`{title}`** (`{detail}`)."
STEP_LINE = "`{shortcode}`, track `{track}`, **`{title}`** (`{detail}`)"
SERVED = ", served on PHP `{php_current}`"
TEST_SERVED = ", test domain on PHP `{test_php}`"
NO_SWEEP = "; mg has no sweep for its PHP yet"
DEPLOY_TO_TEST = "mg will scan `{shortcode}` once this commit is deployed to `{domain}`; production can wait."
UPGRADE_LEFT = ("`{shortcode}` has `upgrade_branch` set to `{upgrade}` on mg; clearing it "
                "(`site-set --shortcode={shortcode} --upgrade=`) is your call.")
SECOND_LINE = "mg will scan `{branch}`'s tip for `{shortcode}`."
MAY_I_SET = ("The server runs commits this branch lacks, so `{branch}` is a second line being prepared "
             "beside the live one. May I run `~/work/rob/mg.robnugen.com/tools/site-standards/site.sh "
             "site-set --shortcode={shortcode} --upgrade={branch}`, so that mg scans `{branch}`'s tip "
             "for `{shortcode}`?")
SERVER_SILENT = "Its server did not say what it runs (`{why}`)."
```

  - In `lookup`, after `site_or_stop`: `lines = []`; when
    `site["skipped_reason"]` is set, `lines.append(FROM_SCAN...)`. Take
    `step = site["open_steps"][0]` and never any other.
  - Rob's step: `say` is the lines so far, then `YOURS`, a blank line, and the
    `how_to`, joined with blank lines between the scan note and `YOURS`
    (`"\n\n".join`). Outcome `say`, no `after`. (Task 6 adds `rules`.)
  - Agent's step: `step_line` is `STEP_LINE`, then `SERVED` and (when
    `test_php` is set) `TEST_SERVED` when `php_current` is set, else
    `NO_SWEEP`, then a full stop. A `test_php` with no `php_current` is not
    printed: the skill adds it only beside the served PHP.
  - `local_head(top)`: `scan.git(top, "rev-parse", "HEAD")` and
    `scan.git(top, "symbolic-ref", "--short", "-q", "HEAD")`, as `scan_site`
    reads them.
  - `second_line`: no `facts["server"]` gives the note `SERVER_SILENT` with
    `facts["server_problem"]` (or `there is no server to ask`, the scanner's
    words) and no question. Otherwise call
    `scan.server_facts.head_and_branch`; `NoAnswer` gives `SERVER_SILENT` with
    its text. With a commit: `scan.git(top, "merge-base", "--is-ancestor",
    server_commit, "HEAD")[0] == 0` is the one-line case (note
    `DEPLOY_TO_TEST` when `facts["test"]` is set and `upgrade_branch` is not;
    note `UPGRADE_LEFT` when `upgrade_branch` is set). Any other exit code
    (1, or 128 for a commit the checkout does not have) is the second-line
    case: with no local branch, nothing; with `upgrade_branch == branch`, the
    note `SECOND_LINE`; otherwise the question `MAY_I_SET`, unless
    `options.leave_upgrade`, which gives nothing.
  - `lookup` returns `{"outcome": "ask", "say": "\n".join(lines), "ask": ...}`
    when `second_line` asked. Lines inside `say` for an agent step are joined
    with single newlines.

- [ ] **Step 4: Run the tests; expect `OK`.**

- [ ] **Step 5: Commit.**

```bash
git add tools/site-standards/next_step.py tools/site-standards/tests/test_next_step_agent.py tools/site-standards/tests/test_next_step_stops.py
git commit -m "Next step: name the step, ask about a second line" -m "Rob's step, the line that says which step an agent has, and the upgrade_branch question now come from the script. The server is asked through server_facts, as the scanner asks it, because a raw git over ssh does not work for a server read through its facts host." -m "<attribution line>"
```

---

### Task 4: The step's check, run here in the scanner's environment

**Files:**
- Modify: `tools/site-standards/next_step.py`, `tools/site-standards/tests/test_next_step_agent.py`

**Interfaces:**
- Consumes: `scan.check_environment(site, env, server_commit, server_branch, php_target)`, `scan.run_check(script, repo, param, site_env) -> (outcome, detail)` with outcome `pass`, `fail` or `unknown`, `scan.php_targets(standards_mcp) -> {path: target}`, `scan.CHECK_NAME`.
- Produces:
  - `NEVER_RUN_IN_A_LOOKUP = {"suite_passes_on_php": "only the scan runs a site's safe suite"}`
  - `check_here(step, facts, top, server_commit, server_branch) -> Check`, where `Check` is a namedtuple `(kind, outcome, line, script, param, site_env)`; `kind` is `"script"`, `"none"` (no script here: mg's own check, or an idea with no check yet) or `"never"`; `outcome` is `pass`, `fail`, `unknown` or `None` for kind `none`.
  - `scan_reminder(shortcode, facts) -> str`

- [ ] **Step 1: Failing tests**, added to `NextStepAgentTest` (each starts
with `self.server_runs(self.first)` unless it says otherwise):

```python
    def test_the_never_run_list_is_exactly_the_safe_suite(self):
        self.assertEqual(next_step.NEVER_RUN_IN_A_LOOKUP,
                         {"suite_passes_on_php": "only the scan runs a site's safe suite"})

    def test_a_passing_check_is_already_done_with_the_scan_reminder(self):
        self.check("echo", 0, "all there")
        self.mg_says(self.site([self.step()]))
        got = self.run_it()
        self.assertEqual(got["outcome"], "say")
        self.assertEqual(got["say"].split("\n")[-1], "Already done here; it's waiting on a deploy.")
        self.assertEqual(got["after"], "Once you've approved and deployed this, run on Lemur 13:\n"
                                       "`~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh demo`")

    def test_the_reminder_names_the_test_domain_when_that_is_enough(self):
        self.check("echo", 0, "all there")
        test = {"domain": "demo-test.example", "php_version": "8.5", "ssh_alias": "stage", "directory": "t"}
        self.mg_says(self.site([self.step()]), self.facts(test=test))
        self.assertEqual(self.run_it()["after"],
                         "Once you've approved and deployed this to `demo-test.example`, run on Lemur 13:\n"
                         "`~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh demo`")

    def test_a_test_domain_beside_an_upgrade_branch_keeps_the_plain_reminder(self):
        self.check("echo", 0, "all there")
        test = {"domain": "demo-test.example", "php_version": "8.5", "ssh_alias": "stage", "directory": "t"}
        self.mg_says(self.site([self.step()]), self.facts(test=test, upgrade_branch="green"))
        self.assertTrue(self.run_it()["after"].startswith("Once you've approved and deployed this, run"))

    def test_a_failing_check_goes_on_without_a_word(self):
        self.mg_says(self.site([self.step()]))      # echo exits 1 from setUp
        got = self.run_it()
        self.assertIn(got["outcome"], ("dispatch", "do"))
        self.assertNotIn("after", got)
        self.assertNotIn("could not tell", got["say"])

    def test_a_check_that_could_not_tell_is_quoted_and_the_run_goes_on(self):
        self.check("echo", 2, "vendor/ is not installed")
        self.mg_says(self.site([self.step()]))
        got = self.run_it()
        self.assertIn(got["outcome"], ("dispatch", "do"))
        self.assertIn("The check could not tell: `vendor/ is not installed`", got["say"].split("\n"))

    def test_the_check_gets_the_scanners_environment_and_the_param(self):
        self.check("echo", 2, "$2|$SITE_SHORTCODE|$SITE_PHP_CURRENT|$SITE_TEST_DOMAIN|$SITE_TEST_PHP|"
                              "$SITE_TEST_SSH_ALIAS|$SITE_TEST_DIRECTORY|$SITE_SERVER_SSH_ALIAS|"
                              "$SITE_SERVER_DIRECTORY|$SITE_SERVER_COMMIT|$SITE_UPGRADE_BRANCH|$1")
        test = {"domain": "demo-test.example", "php_version": "8.5", "ssh_alias": "stage", "directory": "t"}
        self.mg_says(self.site([self.step(check_param="8.5")]), self.facts(test=test, upgrade_branch="green"))
        said = self.run_it()["say"]
        self.assertIn(f"`8.5|demo|8.4|demo-test.example|8.5|stage|t|prod|demo.example|{self.first}|green|"
                      f"{os.path.realpath(self.repo)}`", said)

    def test_a_null_param_reaches_the_check_as_nothing(self):
        self.check("echo", 2, "param=[$2]")
        self.mg_says(self.site([self.step(check_param=None)]))
        self.assertIn("`param=[]`", self.run_it()["say"])

    def test_the_sites_php_target_comes_from_the_registry(self):
        write(os.path.join(self.mcp, "repos.yaml"),
              f"demo:\n  path: {os.path.realpath(self.repo)}\n  php_target: \"8.5\"\n")
        self.check("echo", 2, "target=$SITE_PHP_TARGET")
        self.mg_says(self.site([self.step()]))
        self.assertIn("`target=8.5`", self.run_it()["say"])

    def test_a_safe_suite_is_never_run_in_a_lookup(self):
        marker = os.path.join(self.root, "suite-ran")
        self.check("suite_passes_on_php", 0, "passed", body=f"touch '{marker}'")
        self.mg_says(self.site([self.step(check="suite_passes_on_php", check_param="8.5")]))
        got = self.run_it()
        self.assertFalse(os.path.exists(marker))
        self.assertIn(got["outcome"], ("dispatch", "do"))
        self.assertIn("The check could not tell: `only the scan runs a site's safe suite`",
                      got["say"].split("\n"))

    def test_a_check_with_no_script_here_is_skipped(self):
        self.mg_says(self.site([self.step(check="dh_serves_php")]))
        got = self.run_it()
        self.assertIn(got["outcome"], ("dispatch", "do"))
        self.assertEqual(len(got["say"].split("\n")), 1)

    def test_a_check_name_that_is_not_a_name_is_never_run(self):
        self.mg_says(self.site([self.step(check="../lib")]))
        self.assertIn(self.run_it()["outcome"], ("dispatch", "do"))
```

- [ ] **Step 2: Run, see them fail.**

- [ ] **Step 3: Implement.**

```python
ALREADY_DONE = "Already done here; it's waiting on a deploy."
COULD_NOT_TELL = "The check could not tell: `{line}`"
SCAN_REMINDER = ("Once you've approved and deployed this{where}, run on Lemur 13:\n"
                 "`~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh {shortcode}`")
```

  - `check_here`: `script = os.path.join(HERE, "checks", check + ".sh")`. A
    name in `NEVER_RUN_IN_A_LOOKUP` is kind `never`, outcome `unknown`, line
    the list's sentence, and is decided **before** looking for the file. A
    name that fails `scan.CHECK_NAME` or has no file is kind `none`.
    Otherwise: the standards-mcp directory is
    `os.environ.get("STANDARDS_MCP") or os.path.expanduser("~/work/rob/standards-mcp")`
    (as `scan.main`), `php_target = scan.php_targets(mcp).get(os.path.normpath(top))`,
    `site_env = scan.check_environment(facts, dict(os.environ), server_commit, server_branch, php_target)`,
    and `scan.run_check(script, top, step["check_param"] or "", site_env)`.
    The empty string for a null param is what the scanner passes too; every
    check reads its param as `${2:-}`.
  - `top` is the realpath from `checkout()`, so the path a check is given is
    the one `php_targets` is keyed by.
  - `scan_reminder`: `where` is `` " to `<test.domain>`" `` when
    `facts["test"]` is set and `facts["upgrade_branch"]` is not, else empty.
  - In `lookup`: outcome `pass` appends `ALREADY_DONE` and returns
    `{"outcome": "say", "say": ..., "after": scan_reminder(...)}`; outcome
    `unknown` appends `COULD_NOT_TELL`; `fail` and kind `none` append nothing.
    Then go on to Task 6's part (until then, the placeholder
    `{"outcome": "do", "say": ...}`).

- [ ] **Step 4: Run the tests; expect `OK`.**

- [ ] **Step 5: Commit.**

```bash
git add tools/site-standards/next_step.py tools/site-standards/tests/test_next_step_agent.py
git commit -m "Next step: run the step's check as a scan would" -m "The check gets scan.py's check_environment, built from the same facts, so it answers here what it will answer in the scan. With only the two PHP variables the skill set, a check that needs the test domain passed and the step was called already done." -m "A lookup never runs a site's safe suite: the checks it must not run are a named list, pinned by a test." -m "<attribution line>"
```

---

### Task 5: The brief and rules template

**Files:**
- Create: `tools/site-standards/next_step_brief.md`

The template is text for a model, committed to a public repo. It names no
site. Placeholders are `{{name}}`, lower case and underscores; Task 6 fills
them in one pass and its tests pin the list. The two marker lines are exact.

- [ ] **Step 1: Write the file**, exactly:

````markdown
You are doing one step of mg's site standards for the site `{{shortcode}}`, in the checkout `{{top}}`.

## The step

- Track: {{track}}
- Step: **{{title}}** (its name on mg is `{{slug}}`)
- Where it stands: {{detail}}
- PHP: {{served}}

### Why this step exists

{{explanation}}

### How to do it

{{how_to}}

### The check that decides it

{{check}}

<!-- rules -->
## Rules for this step

1. **Compare before you change.** Read the explanation and the how-to, then
   compare them carefully with this repo's code before changing anything:
   what is already there, what is missing, and what the how-to assumes that
   this repo does differently.
2. **One step only.** Never go on to a second step. Never mark a step done:
   only the scanner ticks a step, from the code the server runs, after Rob
   deploys.
3. **If the step does not fit this site, do not decide alone.** Change
   nothing. Say why, and suggest that Rob mark it NAh: an issue titled
   `{{slug}}.{{shortcode}}` in the site's mg project, set to status NAh.
4. **Work under this project's own rules.** Its `CLAUDE.md`, its project
   memory and the voice of its `git log` decide the branch, the BEGIN commit,
   the bubble size, the commit format and the gates. Never commit on, check
   out or merge into the trunk.
5. **Open a bubble when the project's rules call for one, and leave it
   open.** Never run `git close-bubble`. Never push.
6. **Before each commit, if the repo has a `rector.php`, run its dry run:**
   `~/work/rob/standards-mcp/run-rector.sh {{top}}` (exit 2 means it would
   change something). If Rector would rewrite code the how-to gave word for
   word, commit Rector's form in the same commit, never as a follow-up, and
   say which line differs from the how-to. "Exactly as written" in a how-to
   protects the SQL, the signatures, the docblocks and the error handling;
   the syntax follows the site's Rector level. One site needed two commits
   for a single how-to because it followed the how-to's parentheses to the
   letter.
7. **Finish by running the check above and the project's gates**, each of
   these that the project is set up for, and note each command's exit code:

       ~/work/rob/standards-mcp/run-unit.sh {{top}}
       ~/work/rob/standards-mcp/run-phpcs.sh {{top}}
       ~/work/rob/standards-mcp/run-phpstan.sh {{top}}

8. **Plain words.** Every site here is working; an open step is the next
   thing to do. No warning or alarm wording in commits or in what you report.

<!-- subagent -->
## Because you are a subagent

Nobody can answer a question while you work, and the session that started you
will check what you did for itself.

- You were given this project's `CLAUDE.md` and its memory index, but not the
  memory files. Before your first commit, open every memory file the index
  links about commits, bubbles, branches and gates, and read `git log`.
- Wherever a project rule says "ask Rob" (a bubble of exactly three commits,
  where to branch, a step big enough to need a plan), stop before any commit
  and report **needs Rob**. You cannot talk to him.
- No hook tells you the time. Give a time only as an absolute timestamp you
  read from a command's output, or not at all.

End with exactly one of these three reports, its name alone on the first line:

- **done**: what changed; each commit's hash and subject; the check's line;
  each gate command you ran and its exit code.
- **does not fit**: why, and the NAh issue title from rule 3. Nothing was
  changed.
- **needs Rob**: one question, with your proposal, asked before any commit.
````

- [ ] **Step 2: Check no site is named and git sees the file.**

Run: `git ls-files --others --exclude-standard tools/site-standards`
Expected: `tools/site-standards/next_step_brief.md`. Read the file once more
for any real shortcode, domain or alias; there must be none.

- [ ] **Step 3: Commit.**

```bash
git add tools/site-standards/next_step_brief.md
git commit -m "Add the next-step brief and rules template" -m "The working rules that were section 4 of the /next-mg-step skill, kept beside the script so their wording is versioned and the same on every run. The last part is for a subagent only: it carries what a subagent is not given, and turns every 'ask Rob' into a report." -m "<attribution line>"
```

---

### Task 6: The model, the brief, and the outcomes dispatch and do

**Files:**
- Modify: `tools/site-standards/next_step.py`
- Create: `tools/site-standards/tests/test_next_step_model.py`
- Modify: `tools/site-standards/tests/test_next_step_stops.py` (Rob's step now carries `rules`)

**Interfaces:**
- Consumes: Tasks 2 to 5; `scan.frontmatter(content) -> dict`, `scan.step_name(stem) -> str`.
- Produces:
  - `SUBAGENT_MODELS = ("haiku", "sonnet")`
  - `PLACEHOLDERS = ("shortcode", "top", "track", "title", "slug", "detail", "served", "explanation", "how_to", "check")`
  - `step_model(mg_repo, steps_commit, standard, slug) -> str or None`
  - `check_text(check: Check, top) -> str`
  - `render(values: dict) -> (brief: str, rules: str)`
  - `lookup` complete.

- [ ] **Step 1: Failing tests**, class `NextStepModelTest(NextStepCase)`;
`setUp` adds `self.check("echo", 1, "not yet")` and
`self.server_runs(self.first)`. Helper:

```python
    def restep(self, step=None, track=None):
        """Rewrite the scratch mg's step files, commit, and return the commit."""
        if track is not None:
            write(os.path.join(self.steps, "_track.md"), track)
        if step is not None:
            write(os.path.join(self.steps, "010_a_step.md"), step)
        return self.commit_all(self.mg, "restep")
```

Tests:

```python
    def test_the_tracks_model_is_the_steps_default(self):
        self.mg_says(self.site([self.step()]))
        got = self.run_it()
        self.assertEqual((got["outcome"], got["model"], got["base"]), ("dispatch", "sonnet", self.second))
        self.assertEqual(sorted(got), ["base", "brief", "model", "outcome", "say"])

    def test_the_steps_own_model_wins(self):
        commit = self.restep(step=STEP.replace("check: echo\n", "check: echo\nmodel: opus\n"))
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        got = self.run_it()
        self.assertEqual((got["outcome"], got["model"], got["base"]), ("do", "opus", self.second))
        self.assertEqual(sorted(got), ["base", "model", "outcome", "rules", "say"])

    def test_each_model_goes_where_the_spec_sends_it(self):
        for model, outcome in (("haiku", "dispatch"), ("sonnet", "dispatch"), ("opus", "do"), ("fable", "do")):
            with self.subTest(model=model):
                commit = self.restep(track=TRACK.replace("sonnet", model))
                self.mg_says(self.site([self.step()]), steps_commit=commit)
                self.assertEqual(self.run_it()["outcome"], outcome)

    def test_the_file_is_found_by_the_name_after_the_number(self):
        os.rename(os.path.join(self.steps, "010_a_step.md"), os.path.join(self.steps, "038.9996_a_step.md"))
        commit = self.commit_all(self.mg, "renumber")
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        self.assertEqual(self.run_it()["model"], "sonnet")

    def test_the_model_is_read_at_the_scanned_commit_not_the_working_tree(self):
        self.restep(track=TRACK.replace("sonnet", "opus"))          # newer than the scan
        self.mg_says(self.site([self.step()]))                       # steps_commit: the first one
        self.assertEqual(self.run_it()["model"], "sonnet")

    def test_no_model_anywhere_stops_and_never_guesses(self):
        no_model = "mg's last scan has no model for **`A step`**; a scan will bring it."
        cases = {
            "no line": self.restep(track=TRACK.replace("model: sonnet\n", "")),
            "not a model": self.restep(track=TRACK.replace("sonnet", "gpt")),
            "no scan": None,
            "not a commit name": "HEAD",
            "a commit this checkout lacks": "d" * 40,
        }
        for why, commit in cases.items():
            with self.subTest(why=why):
                self.mg_says(self.site([self.step()]), steps_commit=commit)
                got = self.run_it()
                self.assertEqual(got["outcome"], "say")
                self.assertEqual(got["say"].split("\n")[-1], no_model)
                self.assertNotIn("model", got)

    def test_a_standard_that_is_not_a_name_gives_no_model(self):
        self.mg_says(self.site([self.step()], standard="../.."))
        self.assertEqual(self.run_it()["outcome"], "say")

    def test_a_step_already_done_never_needs_a_model(self):
        self.check("echo", 0, "all there")
        self.mg_says(self.site([self.step()]), steps_commit=None)
        self.assertEqual(self.run_it()["say"].split("\n")[-1], "Already done here; it's waiting on a deploy.")

    def test_the_brief_carries_the_step_the_check_and_all_three_parts(self):
        self.mg_says(self.site([self.step(explanation="Because.", how_to="1. Edit it.")]))
        brief = self.run_it()["brief"]
        top = os.path.realpath(self.repo)
        for piece in (f"for the site `demo`, in the checkout `{top}`", "- Track: Track",
                      "- Step: **A step** (its name on mg is `a_step`)", "- Where it stands: not yet",
                      "- PHP: served on PHP `8.4`", "Because.", "1. Edit it.",
                      "an issue titled\n   `a_step.demo`", f"run-rector.sh {top}", f"run-unit.sh {top}",
                      "## Rules for this step", "## Because you are a subagent", "**needs Rob**",
                      "Just now it said: `not yet`"):
            self.assertIn(piece, brief)
        self.assertNotIn("{{", brief)
        self.assertNotIn("<!--", brief)

    def test_the_check_command_in_the_brief_is_the_one_the_script_ran(self):
        self.mg_says(self.site([self.step(check_param="8.5")]))
        brief = self.run_it()["brief"]
        script = os.path.join(self.here, "checks", "echo.sh")
        line = next(l for l in brief.split("\n") if script in l)
        self.assertTrue(line.strip().startswith("env SITE_"))
        self.assertTrue(line.strip().endswith(f"{script} {os.path.realpath(self.repo)} 8.5"))
        for name in ("SITE_SHORTCODE=demo", "SITE_PHP_CURRENT=8.4", f"SITE_SERVER_COMMIT={self.first}",
                     "SITE_TEST_DOMAIN=''", "SITE_UPGRADE_BRANCH=''"):
            self.assertIn(name, line)
        done = subprocess.run(["bash", "-c", line], capture_output=True, text=True)
        self.assertEqual((done.returncode, done.stdout.strip()), (1, "not yet"))

    def test_a_check_with_no_script_and_a_safe_suite_say_so_in_the_brief(self):
        self.mg_says(self.site([self.step(check="dh_serves_php")]))
        self.assertIn("mg answers this step's check from its own tables; there is nothing to run here.",
                      self.run_it()["brief"])
        self.check("suite_passes_on_php", 0, "passed")
        self.mg_says(self.site([self.step(check="suite_passes_on_php")]))
        self.assertIn("Only the scan runs this step's check (a site's safe suite). Do not run it.",
                      self.run_it()["brief"])

    def test_a_check_that_could_not_tell_is_quoted_in_the_brief_and_the_rules(self):
        self.check("echo", 2, "vendor/ is not installed")
        self.mg_says(self.site([self.step()]))
        self.assertIn("Just now it could not tell: `vendor/ is not installed`", self.run_it()["brief"])
        commit = self.restep(track=TRACK.replace("sonnet", "opus"))
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        self.assertIn("Just now it could not tell: `vendor/ is not installed`", self.run_it()["rules"])

    def test_rules_are_the_rules_part_only_plus_the_check(self):
        commit = self.restep(track=TRACK.replace("sonnet", "opus"))
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        rules = self.run_it()["rules"]
        self.assertTrue(rules.startswith("## Rules for this step"))
        self.assertIn("`a_step.demo`", rules)
        self.assertNotIn("subagent", rules)
        self.assertNotIn("needs Rob", rules)
        self.assertNotIn("{{", rules)

    def test_text_from_mg_is_never_read_as_a_placeholder(self):
        self.mg_says(self.site([self.step(how_to="Write {{title}} and $HOME and \\1 as they are.",
                                          title="{{how_to}}")]))
        brief = self.run_it()["brief"]
        self.assertIn("Write {{title}} and $HOME and \\1 as they are.", brief)
        self.assertIn("- Step: **{{how_to}}**", brief)

    def test_the_template_uses_exactly_the_known_placeholders(self):
        with open(next_step.BRIEF, encoding="utf-8") as handle:
            text = handle.read()
        self.assertEqual(set(re.findall(r"\{\{([a-z_]+)\}\}", text)), set(next_step.PLACEHOLDERS))
        self.assertEqual(text.count("<!-- rules -->\n"), 1)
        self.assertEqual(text.count("<!-- subagent -->\n"), 1)
        self.assertLess(text.index("<!-- rules -->"), text.index("<!-- subagent -->"))

    def test_a_cheaper_session_is_told_which_model_the_step_asks_for(self):
        commit = self.restep(track=TRACK.replace("sonnet", "opus"))
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        line = "This step asks for `opus`; this session runs on `sonnet`."
        self.assertEqual(self.run_it("--session-model=sonnet")["say"].split("\n")[-1], line)
        self.assertEqual(self.run_it("--session-model=sonnet")["outcome"], "do")
        for flag in ((), ("--session-model=opus",), ("--session-model=fable",)):
            self.assertNotIn("asks for", self.run_it(*flag)["say"])

    def test_a_dispatch_never_gets_that_line(self):
        self.mg_says(self.site([self.step()]))
        self.assertNotIn("asks for", self.run_it("--session-model=haiku")["say"])
```

And in `test_next_step_stops.py`, extend `test_robs_step_gives_title_detail_and_how_to`:
`self.assertTrue(got["rules"].startswith("## Rules for this step"))` and
`self.assertEqual(sorted(got), ["outcome", "rules", "say"])`; the "nobody can
reach it" and "up to date" answers must still have no `rules` key (their
existing `assertEqual` on the whole dict already says so).

- [ ] **Step 2: Run, see them fail.**

- [ ] **Step 3: Implement.**

```python
NO_MODEL = "mg's last scan has no model for **`{title}`**; a scan will bring it."
ASKS_FOR = "This step asks for `{model}`; this session runs on `{session}`."
CHECK_RUN = ("Run this exactly as written. It prints one line and exits 0 when the step is done, "
             "1 when it is not yet, 2 when it could not tell.\n\n    {command}\n\n{just_now}")
JUST_SAID = "Just now it said: `{line}`"
JUST_COULD_NOT = "Just now it could not tell: `{line}`"
CHECK_IS_MGS = "mg answers this step's check from its own tables; there is nothing to run here."
CHECK_IS_SCANS = "Only the scan runs this step's check (a site's safe suite). Do not run it."
STEPS_DIR = "tools/site-standards/steps/"
STEP_NAME = re.compile(r"\A[a-z][a-z0-9_]{0,63}\Z")     # Names::STEP
```

  - `step_model`: returns None unless `steps_commit` matches
    `scan.server_facts.SHA` and `standard` and `slug` both match `STEP_NAME`
    (nothing from mg reaches git as anything but a full hash or a plain
    name). `mg_repo` is `scan.git(HERE, "rev-parse", "--show-toplevel")[1].strip()`.
    `git ls-tree -r --name-only <commit> -- tools/site-standards/steps/<standard>/`;
    a non-zero exit (the checkout lacks the commit) is None. Among paths
    whose remainder after that prefix is `<track>/<stem>.md` with a stem not
    starting with `_`, take the one where `scan.step_name(stem) == slug`;
    `git show <commit>:<path>`; `scan.frontmatter(...).get("model")`; when
    that is not in `MODELS`, the same for `<track>/_track.md`; a value not in
    `MODELS` is None.
  - `check_text`: kind `none` is `CHECK_IS_MGS`; kind `never` is
    `CHECK_IS_SCANS`; kind `script` is `CHECK_RUN` whose command is `env`,
    then every `SITE_*` name of `check.site_env` in sorted order as
    `NAME=<shlex.quote(value)>`, then `shlex.quote` of the script, the
    checkout and (only when it is not empty) the param, joined by single
    spaces; `just_now` is `JUST_COULD_NOT` for outcome `unknown`, else
    `JUST_SAID`. The command carries the site's ssh alias and directory: it
    is printed to the caller at run time and is never written to a file.
  - `render(values)`: read `BRIEF`; split once at the line `<!-- rules -->\n`
    and once at `<!-- subagent -->\n`; fill each part with one
    `re.sub(r"\{\{([a-z_]+)\}\}", lambda m: values[m.group(1)], part)` (a
    function replacement, so nothing in a value is read again and a
    backslash stays a backslash; an unknown name is a `KeyError`, which is
    the script breaking). `brief` is the three parts joined; `rules` is the
    middle part followed by a blank line, `### The check that decides it`,
    a blank line and `values["check"]`. Both are stripped of trailing
    whitespace and end without a newline.
  - `values["served"]`: `` served on PHP `8.4` `` with
    `` , test domain on PHP `8.5` `` when set, or
    `mg has no sweep for its PHP yet`.
  - Rob's step: add `"rules": render(values)[1]`, with `check` filled by
    `CHECK_IS_MGS` when the check has no script here, else `CHECK_IS_SCANS`
    for a never-run check, else the `CHECK_RUN` command with an empty
    `just_now` (the script did not run it: a taste step's check is run by
    the session once Rob has picked). For that, `check_here` gains a keyword
    `run=True`; with `run=False` it builds the same `Check` with outcome
    `None` and line `""` and runs nothing. Rob's step needs the server's
    commit for the environment only if a check script exists; ask the server
    (`second_line` without its notes or question) only then.
  - Agent's step, after the check said not done or could not tell:
    `model = step_model(...)`; None appends `NO_MODEL` and returns outcome
    `say`. A model in `SUBAGENT_MODELS` returns
    `{"outcome": "dispatch", "say", "model", "brief", "base"}`; any other
    returns `{"outcome": "do", "say", "model", "rules", "base"}`, with
    `ASKS_FOR` appended to `say` when `options.session_model` is set and
    `MODELS.index(options.session_model) < MODELS.index(model)`. `base` is
    `local_head(top)[0]`.

- [ ] **Step 4: Run the tests; expect `OK`.** Then read one real answer's
shape by eye, without mg:

Run: `cd ~/work/rob/mg.robnugen.com && SCAN_SITE_CMD=false tools/site-standards/next-step.sh; echo "exit $?"`
Expected: one JSON line whose `say` is ``mg refused the lookup: `no answer` ``
(`false` exits 1 and says nothing), then `exit 0`.

- [ ] **Step 5: Commit.**

```bash
git add tools/site-standards/next_step.py tools/site-standards/tests/test_next_step_model.py tools/site-standards/tests/test_next_step_stops.py
git commit -m "Next step: read the model, dispatch or do" -m "The step's model is read from its step file, else its track's, at the commit mg last scanned, so the step files stay the one source and no column carries it. haiku and sonnet steps come back as a brief for a subagent; opus and fable steps come back as rules for the session, where Rob can watch and redirect." -m "A step with no model at that commit is never run on a guess: the script says a scan will bring it." -m "<attribution line>"
```

---

### Task 7: `--confirm --base=<commit>`

**Files:**
- Modify: `tools/site-standards/next_step.py`
- Create: `tools/site-standards/tests/test_next_step_confirm.py`

**Interfaces:**
- Consumes: everything above; the runner scripts `run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh` under `STANDARDS_MCP`, each taking the repo path, printing a line starting `GATE SKIP` and exiting 0 when the repo is not set up for it.
- Produces:
  - `TRUNKS = ("master", "main")`, `GATES = ("run-unit.sh", "run-phpcs.sh", "run-phpstan.sh")`, `GATE_TIMEOUT = 1800`
  - `confirm(options, cwd) -> dict` with exactly the keys `outcome` (`"say"`), `say`, `after`, `clean`; or, for a stop before a step is known, `outcome`, `say`, `clean: False`.
  - `diff_counts(top, base) -> dict` with keys `ignores`, `baselines`, `tests_deleted`, `tests_skipped`.

What `say` holds, one fact a line, in this order (the tests pin each):

| Fact | Lines |
|---|---|
| branch | ``On branch `topic`.`` / ``On `master`, which is the trunk.`` / `Not on a branch.` |
| tree | `The working tree is clean.` / `The working tree has uncommitted changes.` |
| commits | ``No commits since `<base, 7 chars>`.`` / ``2 commits since `<base7>`:`` then ``- `<hash7>` <subject>`` each, oldest first / ``1 commit since `<base7>`:`` / ``HEAD does not contain `<base7>`.`` |
| bubble (4 or more commits only) | ``The first is a `BEGIN ` commit touching one file.`` / ``The first is not a `BEGIN ` commit touching one file.`` |
| check | ``Check `echo`: pass (`<line>`).`` / ``Check `echo`: fail (`<line>`).`` / ``Check `echo`: could not tell (`<line>`).`` / ``Check `dh_serves_php` is mg's own; it has no script here.`` |
| gates | `` `run-unit.sh`: exit 0.`` / `` `run-phpcs.sh`: not set up here.`` / `` `run-phpstan.sh`: not found in standards-mcp.`` |
| diff | ``In the diff: 0 added `@phpstan-ignore` lines, 0 baseline files added or grown, 0 test files deleted, 0 tests newly marked skipped or incomplete.`` |

`clean` is true when: the branch is a branch and not a trunk; the tree is
clean; HEAD contains the base; the check passed or has no script here; every
gate that ran exited 0; and the four counts are all 0. A check that could not
tell, including a safe suite the script will not run, makes `clean` false.
The bubble line is a fact only and does not enter `clean` (the spec's
definition leaves it out). A gate that was not set up or not found does not
enter `clean` either.

- [ ] **Step 1: Failing tests**, class `NextStepConfirmTest(NextStepCase)`.
`setUp`: `self.check("echo", 0, "all there")`, the three gates written with
`self.gate(name)`, `self.server_runs(self.first)`,
`self.mg_says(self.site([self.step()]))`, `self.base = self.second`. Helpers:

```python
    def add(self, path, text, message):
        write(os.path.join(self.repo, path), text)
        return self.commit_all(self.repo, message)

    def confirm(self):
        return self.run_it("--confirm", "--base=" + self.base)

    def lines(self):
        return self.confirm()["say"].split("\n")
```

Tests (each asserts the line and `clean`):

```python
    def test_work_done_cleanly_is_clean(self):
        one = self.add("classes/A.php", "<?php\n", "Add A")
        two = self.add("classes/B.php", "<?php\n", "Add B")
        got = self.confirm()
        self.assertEqual(got["say"].split("\n"), [
            "On branch `topic`.",
            "The working tree is clean.",
            f"2 commits since `{self.base[:7]}`:",
            f"- `{one[:7]}` Add A",
            f"- `{two[:7]}` Add B",
            "Check `echo`: pass (`all there`).",
            "`run-unit.sh`: exit 0.",
            "`run-phpcs.sh`: exit 0.",
            "`run-phpstan.sh`: exit 0.",
            "In the diff: 0 added `@phpstan-ignore` lines, 0 baseline files added or grown, "
            "0 test files deleted, 0 tests newly marked skipped or incomplete.",
        ])
        self.assertIs(got["clean"], True)
        self.assertEqual(got["after"], "Once you've approved and deployed this, run on Lemur 13:\n"
                                       "`~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh demo`")
        self.assertEqual(sorted(got), ["after", "clean", "outcome", "say"])

    def test_the_gates_are_given_the_checkout(self):
        self.confirm()
        with open(os.path.join(self.root, "gates.log"), encoding="utf-8") as handle:
            self.assertEqual(handle.read().split(), [os.path.realpath(self.repo)] * 3)

    def test_a_lookup_never_runs_a_gate(self):
        self.check("echo", 1, "not yet")
        self.run_it()
        self.assertFalse(os.path.exists(os.path.join(self.root, "gates.log")))

    def test_the_trunk_is_not_clean(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "master"], check=True)
        self.base = self.first
        self.assertIn("On `master`, which is the trunk.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_detached_head_is_not_clean(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "--detach"], check=True)
        self.assertIn("Not on a branch.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_uncommitted_changes_are_not_clean(self):
        write(os.path.join(self.repo, "stray.txt"), "x\n")
        self.assertIn("The working tree has uncommitted changes.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_no_commits_is_said(self):
        self.assertIn(f"No commits since `{self.base[:7]}`.", self.lines())

    def test_a_base_outside_head_is_not_clean(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "other", self.first], check=True)
        self.assertIn(f"HEAD does not contain `{self.base[:7]}`.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_base_that_is_no_commit_is_a_usage_error(self):
        self.base = "e" * 40
        with self.assertRaises(next_step.Usage):
            self.confirm()

    def test_four_commits_opened_by_a_begin_commit(self):
        self.add("version.php", "<?php\n", "BEGIN the step")
        for name in ("A", "B", "C"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertIn("The first is a `BEGIN ` commit touching one file.", self.lines())
        self.assertIs(self.confirm()["clean"], True)

    def test_four_commits_not_opened_by_a_begin_commit(self):
        for name in ("A", "B", "C", "D"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertIn("The first is not a `BEGIN ` commit touching one file.", self.lines())

    def test_a_begin_commit_touching_two_files_is_not_one(self):
        write(os.path.join(self.repo, "other.php"), "<?php\n")
        self.add("version.php", "<?php\n", "BEGIN the step")
        for name in ("A", "B", "C"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertIn("The first is not a `BEGIN ` commit touching one file.", self.lines())

    def test_three_commits_say_nothing_about_begin(self):
        for name in ("A", "B", "C"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertFalse([l for l in self.lines() if "BEGIN" in l])

    def test_a_failing_check_is_not_clean(self):
        self.check("echo", 1, "still missing")
        self.assertIn("Check `echo`: fail (`still missing`).", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_check_that_could_not_tell_is_not_clean(self):
        self.check("echo", 2, "vendor/ is not installed")
        self.assertIn("Check `echo`: could not tell (`vendor/ is not installed`).", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_mgs_own_check_has_no_script_and_does_not_count_against_clean(self):
        self.mg_says(self.site([self.step(check="dh_serves_php")]))
        self.assertIn("Check `dh_serves_php` is mg's own; it has no script here.", self.lines())
        self.assertIs(self.confirm()["clean"], True)

    def test_a_safe_suite_is_not_run_by_confirm_either(self):
        marker = os.path.join(self.root, "suite-ran")
        self.check("suite_passes_on_php", 0, "passed", body=f"touch '{marker}'")
        self.mg_says(self.site([self.step(check="suite_passes_on_php")]))
        got = self.confirm()
        self.assertFalse(os.path.exists(marker))
        self.assertIn("Check `suite_passes_on_php`: could not tell "
                      "(`only the scan runs a site's safe suite`).", got["say"].split("\n"))
        self.assertIs(got["clean"], False)

    def test_a_failing_gate_is_not_clean(self):
        self.gate("run-phpstan.sh", exit_code=1)
        self.assertIn("`run-phpstan.sh`: exit 1.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_gate_the_repo_is_not_set_up_for_is_said_and_does_not_count(self):
        self.gate("run-phpcs.sh", exit_code=0, out="GATE SKIP: no phpcs config in /somewhere")
        os.remove(os.path.join(self.mcp, "run-unit.sh"))
        lines = self.lines()
        self.assertIn("`run-phpcs.sh`: not set up here.", lines)
        self.assertIn("`run-unit.sh`: not found in standards-mcp.", lines)
        self.assertIs(self.confirm()["clean"], True)

    def diff_line(self):
        return [l for l in self.lines() if l.startswith("In the diff:")][0]

    def test_an_added_phpstan_ignore_is_counted_in_php_only(self):
        self.add("classes/A.php", "<?php\n$x = f(); // @phpstan-ignore argument.type (reason)\n", "Add A")
        self.add("notes.md", "how to write @phpstan-ignore\n", "Notes")
        self.assertIn("1 added `@phpstan-ignore` lines", self.diff_line())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_removed_phpstan_ignore_is_not_counted(self):
        self.base = self.add("classes/A.php", "<?php\n$x = f(); // @phpstan-ignore argument.type\n", "Add A")
        self.add("classes/A.php", "<?php\n$x = f();\n", "Fix A")
        self.assertIn("0 added `@phpstan-ignore` lines", self.diff_line())

    def test_a_new_or_grown_baseline_is_counted(self):
        self.add("phpstan-baseline.neon", "parameters:\n", "Add a baseline")
        self.assertIn("1 baseline files added or grown", self.diff_line())
        self.base = self.commit(self.repo, "mark")
        self.add("phpstan-baseline.neon", "parameters:\n  ignoreErrors:\n    - one\n", "Grow it")
        self.assertIn("1 baseline files added or grown", self.diff_line())
        self.base = self.commit(self.repo, "mark again")
        self.add("phpstan-baseline.neon", "parameters:\n", "Shrink it")
        self.assertIn("0 baseline files added or grown", self.diff_line())

    def test_a_deleted_test_file_is_counted_and_a_moved_one_is_not(self):
        body = "<?php\nfinal class ATest { public function testA(): void { $this->assertTrue(true); } }\n"
        self.base = self.add("Tests/Unit/ATest.php", body, "Add a test")
        subprocess.run([*GIT, "-C", self.repo, "mv", "Tests/Unit/ATest.php", "Tests/Unit/BTest.php"], check=True)
        self.commit_all(self.repo, "Move it")
        self.assertIn("0 test files deleted", self.diff_line())
        os.remove(os.path.join(self.repo, "Tests/Unit/BTest.php"))
        self.commit_all(self.repo, "Delete it")
        self.assertIn("1 test files deleted", self.diff_line())
        self.assertIs(self.confirm()["clean"], False)

    def test_tests_newly_skipped_or_incomplete_are_counted(self):
        self.add("Tests/Unit/ATest.php",
                 "<?php\n$this->markTestSkipped('later');\n$this->markTestIncomplete();\n"
                 "#[Skip]\n$scenario->skip();\n * @skip\n", "Add a skipped test")
        self.assertIn("5 tests newly marked skipped or incomplete", self.diff_line())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_stop_in_confirm_is_not_clean(self):
        self.mg_fails(255, "ssh: connect to host mg port 22: Connection refused\n")
        got = self.confirm()
        self.assertTrue(got["say"].startswith("I can't reach mg from here"))
        self.assertIs(got["clean"], False)
        self.assertNotIn("after", got)
```

- [ ] **Step 2: Run, see them fail.**

- [ ] **Step 3: Implement `confirm` and `diff_counts`.**

  - `confirm` starts as `lookup` does: `checkout`, `ask_mg`, `site_or_stop`,
    first open step; a `Stop` from any of them is returned with
    `"clean": False` added. Rob's step as the first open step is treated like
    any other: its check is re-run.
  - `--base`: `scan.git(top, "rev-parse", "--verify", "--quiet", base + "^{commit}")`;
    a non-zero exit raises `Usage("--base= is not a commit in this checkout.")`.
    Use the full hash it prints from there on.
  - Branch: `local_head(top)`; a trunk is a name in `TRUNKS`, or the branch
    `origin/HEAD` points at when the checkout has one
    (`git symbolic-ref --short -q refs/remotes/origin/HEAD`, minus `origin/`).
  - Tree: `scan.git(top, "status", "--porcelain")` empty.
  - Commits: `merge-base --is-ancestor <base> HEAD`, then
    `git log --reverse --format=%H%x09%s <base>..HEAD`. With four or more,
    the first is a BEGIN commit when its subject starts with `BEGIN ` (the
    same test `git close-bubble` applies) and
    `git show --name-only --format= <hash>` lists exactly one path.
  - Check: `second_line` for the server's commit (its notes and question are
    not used), then `check_here`; kind `none` gives the "mg's own" line.
  - Gates, in `GATES` order: a script that is not an executable file under
    the standards-mcp directory is "not found in standards-mcp". Otherwise
    run `[script, top]` with `stdin=subprocess.DEVNULL`, output captured,
    `timeout=GATE_TIMEOUT`; exit 0 with a stdout line starting `GATE SKIP`
    is "not set up here"; a timeout is reported as `exit 124`.
  - `diff_counts`, all from `<base>` to `HEAD`:
    - `ignores`: added lines (a `+` that is not the `+++` header) holding
      `@phpstan-ignore`, in files ending `.php`, from
      `git diff --unified=0 <base> HEAD -- '*.php'`.
    - `baselines`: paths whose basename holds `baseline` (any case) and ends
      `.neon`, `.php`, `.xml` or `.json`, which `git diff --numstat` shows
      with more lines added than deleted (a new file is all additions).
    - `tests_deleted`: paths from
      `git diff --name-status --diff-filter=D -M <base> HEAD` that end `.php`
      and have a directory named `tests` or `Tests` in them, or a basename
      ending `Test.php`, `Cest.php` or `Cept.php`. `-M` keeps a moved test
      from counting.
    - `tests_skipped`: added lines in `.php` files matching
      `markTestSkipped|markTestIncomplete|#\[(Skip|Incomplete)\b|\$scenario->(skip|incomplete)\(|@(skip|incomplete)\b`.
  - `after` is `scan_reminder(...)`, always, when a step was found.

- [ ] **Step 4: Run the tests; expect `OK`.**

- [ ] **Step 5: Commit.**

```bash
git add tools/site-standards/next_step.py tools/site-standards/tests/test_next_step_confirm.py
git commit -m "Next step: --confirm finds the facts itself" -m "After the work, whoever did it, the script reports what it can see for itself: the branch and tree, the commits since the base, the step's check re-run, the project's gates with their exit codes, and what the diff added that hides findings or drops tests. It trusts no report, so a subagent's 'done' is never the last word." -m "clean is a rule over those facts and not a verdict on the work: a legitimate @phpstan-ignore makes it false and leaves the bubble for Rob." -m "<attribution line>"
```

---

### Task 8: The `CLAUDE.md` paragraph, the gates, and closing the bubble

**Files:**
- Modify: `CLAUDE.md` (the "Site standards" list)

- [ ] **Step 1: Add one bullet** to the "Site standards (which sites are
behind)" list, directly after the bullet that begins
"**A check can be remembered per commit.**":

```markdown
- **`/next-mg-step` is a script plus a named model.** `tools/site-standards/next-step.sh` (logic in `next_step.py`), run from inside a site's checkout, does the lookup the skill used to do by prose: it asks mg over ssh (`site.sh next-step`), asks the site's server through `server_facts`, runs the first open step's check in the scanner's environment (`scan.py`'s `check_environment`), and prints one JSON object whose `outcome` is `say`, `ask`, `dispatch` or `do`. A step's `model:` line (else its track's, in `_track.md`) is read with git from the step files at the commit mg last scanned, so a new or changed line counts after the first scan that follows its deploy; a step with no model there is never run on a guess. `haiku` and `sonnet` steps are dispatched to a subagent with the `brief`; `opus` and `fable` steps are done by the session under the `rules`. Both texts come from `tools/site-standards/next_step_brief.md`, which names no site. `--confirm --base=<commit>` reports what it finds itself after the work (branch, tree, commits, the check, the gates, what the diff added) and a `clean` verdict; only a clean result lets the session close the bubble. The script never writes to a repo, never runs `site-set` (it asks), and never runs a site's safe suite (`NEVER_RUN_IN_A_LOOKUP`). Its sentences are pinned word for word in `tests/test_next_step_*.py`; change a sentence and its test together. The skill in `~/.claude/skills/next-mg-step/` holds no rules of its own. The script and the checks are read from this checkout's working tree, so they work as soon as the checkout is on a branch that has them.
```

- [ ] **Step 2: Commit.**

```bash
git add CLAUDE.md
git commit -m "CLAUDE.md: how /next-mg-step works now" -m "The lookup moved from a skill's prose into next-step.sh, and a step's model now comes from its step file. The next agent to touch either needs to know where the rules live and that the skill restates none of them." -m "<attribution line>"
```

- [ ] **Step 3: Count what git sees.**

Run: `git ls-files tools/site-standards | grep -E 'next[-_]step'`
Expected, exactly:

```
tools/site-standards/next-step.sh
tools/site-standards/next_step.py
tools/site-standards/next_step_brief.md
tools/site-standards/tests/next_step_case.py
tools/site-standards/tests/test_next_step_agent.py
tools/site-standards/tests/test_next_step_confirm.py
tools/site-standards/tests/test_next_step_model.py
tools/site-standards/tests/test_next_step_stops.py
```

Run: `git ls-files -s tools/site-standards/next-step.sh`
Expected: mode `100755`.

- [ ] **Step 4: A scan is not blocked by this bubble.**

Run: `git diff --stat <the BEGIN commit>~1 HEAD -- tools/site-standards/checks tools/site-standards/lib.sh tools/site-standards/scan.py tools/site-standards/scan.sh tools/site-standards/memo.py tools/site-standards/server_facts.py`
Expected: no output. (Those are the paths `scan.checks_match` compares with
what mg has deployed.)

- [ ] **Step 5: The gates.**

```bash
cd ~/work/rob/mg.robnugen.com
python3 -I -m unittest discover -s tools/site-standards/tests
~/work/rob/standards-mcp/run-unit.sh    ~/work/rob/mg.robnugen.com
~/work/rob/standards-mcp/run-phpcs.sh   ~/work/rob/mg.robnugen.com
~/work/rob/standards-mcp/run-phpstan.sh ~/work/rob/mg.robnugen.com
```

Expected: `OK` from unittest; the Codeception Unit suite green; `PHPCS PASS`;
PHPStan with no errors. Then `check_tests(repo="mg")` from the `standards`
MCP server, expecting `status: pass`. All of them must pass before the next
step.

- [ ] **Step 6: One fresh reviewer** over the bubble against the spec's
section 3 and this part's "Review focus" list, given the code and the spec
and not a summary. Fix what it finds, each fix its own commit.

- [ ] **Step 7: Close the bubble.** Use the `close-bubble` skill.

```bash
git branch --show-current        # the topic branch, never master
git close-bubble --dry-run       # must name "BEGIN next-step script and its brief"
git close-bubble "DONE next-step script and its brief"
```

If Rob gave a closing message, use his words exactly in place of that one.
Do not push. Tell Rob the bubble is closed on the topic branch and that Tasks
9 and 10 wait for his deploy and a scan.

---

### Task 9: The live trial on a `sonnet` step (after Rob deploys and scans)

Nothing here is committed to mg unless the trial says the template must
change. The old skill is still installed and still works.

**What must be true first (ask Rob; do not check by ssh):**
- Rob has deployed an mg commit that holds Part 1 and this bubble, and has run
  `tools/site-standards/scan.sh` since (so mg's `steps_commit` is a commit
  whose step files carry `model:` lines).
- The mg checkout on Lemur 13 is on a branch that has `next-step.sh`.
- Rob names a site whose first open step is an agent's step on a track whose
  model is `sonnet`, and opens a session in that site's checkout. The site's
  name stays out of every committed file.

- [ ] **Step 1: Run the lookup in that session.**

Run: `~/work/rob/mg.robnugen.com/tools/site-standards/next-step.sh --session-model=<the session's model>`
Expected: one JSON object with `"outcome": "dispatch"` and
`"model": "sonnet"`. Any other outcome: show `say` to Rob and pick another
site, or stop; the trial needs a dispatch. If `say` is the "no model"
sentence, the scan predates the model lines: ask Rob to scan.

- [ ] **Step 2: Note what the session is already allowed to run.** Look in
`~/.claude/settings.json` and the site's `.claude/settings.local.json` for an
allow rule that covers `~/work/rob/standards-mcp/run-*.sh` or `docker`. Write
down whether one exists: it decides how to read Step 4.

- [ ] **Step 3: Dispatch, as the new skill will.** Show `say` to Rob. Start
one Agent with `model: "sonnet"` and the `brief` as its whole prompt,
unchanged. Do not run the step's commands yourself while it works.

- [ ] **Step 4: The permission check.** Ask Rob to watch for a permission
prompt naming a `standards-mcp/run-*.sh` script or `docker` while the
subagent works. When it returns, read its report's list of gate commands and
exit codes. Exactly one of these is the result:

| Result | How it shows |
|---|---|
| **A. Gates ran, no prompt** | the report lists each gate with an exit code; Rob saw no prompt (an allow rule from Step 2 covered them) |
| **B. Gates ran after a prompt Rob saw** | Rob approved a prompt; the report lists exit codes |
| **C. Refused unseen** | Rob saw no prompt and the report says a gate command was denied, was not run, or lists no exit code for it |

- [ ] **Step 5: Confirm, as the new skill will.**

Run: `~/work/rob/mg.robnugen.com/tools/site-standards/next-step.sh --confirm --base=<base from Step 1>`
Expected: `say` with the facts, `after`, and `clean`. Show `say` to Rob. With
`clean: true` and a bubble opened, close it by the site's rules
(`close-bubble` skill); otherwise leave it and say it is open for Rob. Never
push. End with `after`. The site's work is real work: Rob deploys and scans
it like any other step.

- [ ] **Step 6: Act on the result.**

  - **A or B:** the template stays as it is. Go to Task 10.
  - **C:** the gates run only in `--confirm`, and the brief must say so. On
    mg, on a new branch off the tip (two commits, so no bubble):
    1. In `tools/site-standards/next_step_brief.md`, rule 7 stays as it is
       (it is shared with a session, which can run Docker). In the subagent
       part, add this item after the timestamp item:

           - Do not run the gates in rule 7. They need Docker, which a
             subagent is not allowed to start here. Run the check only; the
             session runs the gates after you return.

       and change the **done** report's last clause from "each gate command
       you ran and its exit code" to "and that the gates were left to the
       session".
    2. In `test_next_step_model.py`, add to
       `test_the_brief_carries_the_step_the_check_and_all_three_parts` the
       piece `"Do not run the gates in rule 7."`, and to
       `test_rules_are_the_rules_part_only_plus_the_check`
       `self.assertNotIn("Do not run the gates", rules)`.
    3. Run the Python tests, commit the template and the test separately
       (`Brief: a subagent leaves the gates to the session`, body: what the
       trial showed), and tell Rob the branch is there for him. The template
       is read from the working tree, so it takes effect when the mg checkout
       is on that branch; no deploy or scan is needed for it.
  - **Anything else that went wrong in the trial** (a sentence that reads
    badly, a rule the subagent misread): fix the script or the template with
    its test, the same way, before Task 10. Do not patch it in the skill.

---

### Task 10: Switch the skill over (`~/.claude`, its own git repo)

**Files:**
- Replace: `~/.claude/skills/next-mg-step/SKILL.md`
- Modify: `~/.claude/projects/-home-thunderrabbit-work-rob-mg-robnugen-com/memory/project_next_mg_step_806.md`

Do this only after Task 9 ended in A or B, or in C with its fix on the branch
the mg checkout is on.

- [ ] **Step 1: Replace `SKILL.md`** with exactly this. The `name` and
`description` are unchanged, and there is no `model:` line.

````markdown
---
name: next-mg-step
description: Do this repo's first open step on mg's standards page (/admin/dh/standards.php), or say plainly there is none. Use only when Rob types /next-mg-step. Not the same as "next step" after a saved plan and /clear.
---

# /next-mg-step

mg's standards page measures each site against written-up steps. A script
looks up which step this repo owes and decides what happens next. This skill
only runs the script and does what its answer says. Every rule is in the
script; add none, and do not rephrase what it prints.

The script is `~/work/rob/mg.robnugen.com/tools/site-standards/next-step.sh`,
called `next-step.sh` below. Run it from inside this repo.

Two things about tone: no warning or alarm wording (every site is working; an
open step is just the next thing to do), and after a stop add nothing
speculative.

## 1. Run the script

```bash
~/work/rob/mg.robnugen.com/tools/site-standards/next-step.sh --session-model=<haiku|sonnet|opus|fable>
```

Give `--session-model=` the model this session runs on. If the file is not
there, say "mg's checkout is on a branch without next-step.sh, so
/next-mg-step can't run." and stop. If it exits non-zero, show what it
printed on stderr and stop.

It prints one JSON object. Show its `say` to Rob exactly as it is. Then act on
`outcome`:

## 2. `say`

Nothing more to do. One exception: when the answer has `rules` (the step is
Rob's) and its how-to has an agent propose something for Rob to pick, end
with:

> Want to work it through together? I'll list what the check found here and propose a shape for the first group; nothing changes until you pick.

If Rob says yes, do only that: analyse, propose, and wait for his choice
before touching code. Then do the work yourself under `rules`, leave any
bubble open, and go to section 6 with the commit HEAD was at before your
first commit as the base.

## 3. `ask`

Put the `ask` question to Rob and stop. When he answers, run the script again:

- he named a shortcode: add `--shortcode=<code>`;
- he said yes to setting `upgrade_branch`: run the `site.sh site-set` command
  the question quoted, then the script again;
- he said no: add `--leave-upgrade`.

## 4. `dispatch`

Start one Agent with `model` set to the answer's `model` and the answer's
`brief` as the whole prompt, unchanged. When it returns, relay its report.

- **done**: go to section 6.
- **does not fit**: relay why and the NAh issue it suggests, and stop.
- **needs Rob**: put its question to Rob. Send his answer to the same
  subagent (SendMessage; load it with ToolSearch if it is deferred). If that
  subagent is gone (a new session, a `/clear`), start a fresh one with the
  brief, the proposal and Rob's answer.

## 5. `do`

Do the step yourself under the answer's `rules`, leaving any bubble open.

## 6. Confirm

```bash
~/work/rob/mg.robnugen.com/tools/site-standards/next-step.sh --confirm --base=<base>
```

with the `base` the first answer gave (and `--shortcode=` if section 3 added
one). Show its `say` exactly as it is.

- `clean` is true and the work opened a bubble: close it by this project's
  rules (the `close-bubble` skill).
- Otherwise: leave everything as it is and say the bubble is open for Rob.

Never push.

## 7. Last words

Whenever an answer had `after`, end with it, exactly as given.
````

- [ ] **Step 2: Check it against the spec's section 4**, line by line: seven
steps, the taste-step offer, the two tone rules, no rule the script already
holds, no site named. The two examples in the old skill that named real sites
are gone and nothing replaces them here (the Rector one lives, without a
name, in the template).

- [ ] **Step 3: Update the memory file** `project_next_mg_step_806.md`: one
dated paragraph saying the lookup is now `next-step.sh`, that models come
from `model:` lines read at the scanned commit, and which of A, B or C the
trial gave, with absolute dates. Keep the site's name out if it would not
already be in that file.

- [ ] **Step 4: Commit in `~/.claude`**, on whatever branch that repo has
checked out (do not switch branches there; if the git wrapper blocks the
commit, stop and ask Rob):

```bash
git -C ~/.claude status --short
git -C ~/.claude add skills/next-mg-step/SKILL.md projects/-home-thunderrabbit-work-rob-mg-robnugen-com/memory/project_next_mg_step_806.md
git -C ~/.claude commit -m "next-mg-step: run the script, hold no rules" -m "The lookup, the stop sentences and the working rules moved into mg's tools/site-standards/next-step.sh, so the same answer from mg gives the same words every run, and a step runs on the model its step file names. The skill is now a dispatcher over the script's four outcomes." -m "<attribution line>"
```

Any other dirty file `git status` shows there is handled by the global rule
for `~/.claude` (tool churn is ignored and untracked, never committed).

- [ ] **Step 5: First real use.** Rob types `/next-mg-step` in a site of his
choosing. Watch one whole run under the new skill; anything that needed
judgement is a rule that belongs in the script, so note it for him.

---

### Open questions

1. (Settled: `fable` is the tier above `opus`, so the order `haiku`, `sonnet`,
   `opus`, `fable` in `MODELS` stands.)
2. **`clean` for a step whose check is a safe suite.** The script never runs
   `suite_passes_on_php`, so `--confirm` reports "could not tell" and `clean`
   is false: such a step's bubble is always left for Rob. The spec says
   `clean` needs the check to have "passed (or has no script)", which this
   follows; say if a never-run check should count like one with no script.
3. **Should the bubble line enter `clean`?** Four or more commits whose first
   is not a one-file `BEGIN ` commit is reported but, by the spec's
   definition, does not make `clean` false, so the session would go on to
   `git close-bubble`, which then finds no BEGIN and refuses by itself.
4. (Settled in the plan's header: three bubbles in a row, none nested.)
