# Step Position From Filename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A step file named `<number>_<name>.md` gets its position from the number and its step name from the rest, so Rob's 69 renamed files load and every step keeps the name mg already knows.

**Architecture:** One function in `Inventory\Standards\Names` splits a filename stem into position and name. `StepTree` uses it instead of the `position:` frontmatter line and the whole stem. `scan.py` strips the number by the same rule before it reports a step. No database change.

**Tech Stack:** PHP 8.5 (Codeception Unit suite, phpcs, PHPStan, all in Docker), Python 3 `unittest`.

**Spec:** `docs/superpowers/specs/2026-11-13-step-position-from-filename-design.md`

## Global Constraints

- Branch `position-from-filename`, already open with BEGIN (0.83.0) and the design. Never checkout or merge into master. Do not push. Do not close the bubble; Rob says when.
- Rob's 69 renames are uncommitted in the working tree until Task 4. Until then stage files by name, never `git add -A` or `git add .`.
- No migration, no renamed issue, no change to any step name. Step names sent to and stored on mg are the same strings as before.
- Number: digits, optionally a dot and 1 to 4 digits; value from 0 up to but not including 65536. Name: `[a-z][a-z0-9_]{0,63}`.
- `_track.md` keeps its `position:` line and its 1 to 65535 rule. Directory names are not numbered.
- This repo is public: fixtures use `mg` or made-up names.
- Commits: subject 50 chars or fewer, imperative, body says why, multiple `-m` flags, never a heredoc, last `-m` is `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.
- Before calling any task done: `~/work/rob/standards-mcp/run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh`, each with `~/work/rob/mg.robnugen.com`. The pre-commit hook is the stricter judge; fix what it reports, never `--no-verify`.
- `classes/Inventory/Standards/*` runs only on the server (PHP 8.5); `scan.py` runs on Lemur 13's Python 3.

## Review Focus

1. `10_5_foo.md` (underscore typed for a dot): refused, not read as step `5_foo` at position 10. Test in Task 1.
2. Two files with the same name and different numbers (a renumber that left the old file behind): refused with both paths named. Test in Task 2.
3. A step file that still has `position:` (a branch written before this change, merged later): refused, naming the file. Test in Task 2; the real-files test in Task 4 catches it before deploy.
4. An unnumbered step file (same cause): refused with a message that shows the expected form. Test in Task 2.
5. PHP and Python splitting a filename differently, so the scanner reports a name the importer does not have: both tests use the same table of cases (Tasks 1 and 3).

---

### Task 1: `Names` splits a step filename

**Files:**
- Modify: `classes/Inventory/Standards/Names.php`
- Test: `Tests/Unit/StandardsNamesTest.php`

**Interfaces:**
- Produces: `Names::stepFile(string $stem): ?array` returning `array{position: float, name: string}` or `null`. `Names::isStepName()` now requires a first letter.

- [ ] **Step 1: Write the failing tests** in `StandardsNamesTest`

```php
public function testAStepFileIsANumberThenAName(): void
{
    $this->assertSame(['position' => 30.0, 'name' => 'autoloader'], Names::stepFile('030_autoloader'));
    $this->assertSame(['position' => 38.9996, 'name' => 'remove_unused_print_robs'], Names::stepFile('038.9996_remove_unused_print_robs'));
    $this->assertSame(['position' => 0.0, 'name' => 'phpstan_level_0'], Names::stepFile('000_phpstan_level_0'));
    $this->assertSame(['position' => 5.0, 'name' => 'composer_platform_pinned'], Names::stepFile('05_composer_platform_pinned'));
    $this->assertSame(['position' => 65535.9999, 'name' => 'last'], Names::stepFile('65535.9999_last'));
}

public function testWhatIsNotAStepFile(): void
{
    foreach (
        [
            'autoloader',          // no number
            '10_5_foo',            // underscore typed for a dot
            '10.5.1_foo',          // two dots
            '1.00001_foo',         // five decimals
            '1._foo',              // a point and nothing after it
            '.5_foo',              // nothing before the point
            '65536_foo',           // out of range
            '10_',                 // no name
            '10_Foo',              // capital
            '10_has-hyphen',
            '10-foo',
            '-1_foo',
            '10_' . str_repeat('a', 65),
        ] as $stem
    ) {
        $this->assertNull(Names::stepFile($stem), $stem);
    }
}
```

Extend `testStepNames` with: `isStepName('5_foo')` is false, `isStepName('phpstan_level_0')` is true. If that test or `testNoteTitleRoundTrips` asserts a digit-first name is valid, change that case to a letter-first one.

- [ ] **Step 2: Run and see them fail**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`
Expected: FAIL, `Call to undefined method Inventory\Standards\Names::stepFile()`.

- [ ] **Step 3: Implement**

In `Names.php`: change `STEP` to `'[a-z][a-z0-9_]{0,63}'`; add `public static function stepFile(string $stem): ?array` with `@return array{position: float, name: string}|null`, matching `\A(\d+(?:\.\d{1,4})?)_(STEP)\z` and returning null when the number is 65536 or more. Rewrite the class comment: a step file is `<number>_<name>.md`; the number is its position and may have a dot; the name is letters, digits and underscores starting with a letter, so the one dot in `<step>.<shortcode>` still separates them.

- [ ] **Step 4: Run and see them pass**

Run the three gates. Expected: all pass. (`StandardsStepTreeTest` still passes: its fixture names start with letters and `StepTree` is unchanged.)

- [ ] **Step 5: Commit**

```bash
git add classes/Inventory/Standards/Names.php Tests/Unit/StandardsNamesTest.php
git commit -m "Split a step filename into position and name" -m "A step file is now <number>_<name>.md. Names start with a letter so the number ends at one underscore and nowhere else." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 2: `StepTree` takes position and name from the filename

**Files:**
- Modify: `classes/Inventory/Standards/StepTree.php`
- Test: `Tests/Unit/StandardsStepTreeTest.php`, `Tests/Unit/StandardsScanPayloadTest.php` (fixture at line 31)

**Interfaces:**
- Consumes: `Names::stepFile(string $stem): ?array{position: float, name: string}`
- Produces: `StepDef->slug` is the name without the number; `StepDef->position` is the number. `StepTree::fromFiles()` signature unchanged.

- [ ] **Step 1: Change the fixtures and tests** in `StandardsStepTreeTest`

- `STEP` becomes `'template/rector/10_rector_php_8_5_rules.md'`; remove `position: 10\n` from its content in `GOOD`.
- `testParsesAStandardATrackAndAStep`: still asserts slug `rector_php_8_5_rules` and position `10.0`.
- `testTracksAndStepsComeBackInPositionOrder`: files `20_a_later_step.md` and `5_an_earlier_step.md` with identical content; order is `an_earlier_step`, `rector_php_8_5_rules`, `a_later_step`.
- `testAStepCanSitBetweenTwoWholePositions`: files `10.0001_just_after.md`, `10.9999_just_before.md`, `11_whole_eleven.md`, `10.5_half_way.md`; same expected order as today.
- Replace the three position-refusal tests (`MoreThanFourDecimalPlaces`, `APointAndNothingAfterIt`, `NotANumber`) with the tests below. Keep one test that a `_track.md` with `position: abc` is still refused.
- `testRefusesTheSameStepNameTwiceInOneStandard`: the tooling copy is `template/tooling/10_rector_php_8_5_rules.md`.
- `testRefusesAStepNameWithAHyphen`: file `template/rector/10_has-hyphen.md`.
- `testRefusesAFileAtTheWrongDepth`: `template/rector/deeper/10_extra.md`.

New tests:

```php
public function testPositionZeroIsAllowed(): void
{
    $files = self::GOOD + ['template/rector/000_first_of_all.md' => self::GOOD[self::STEP]];
    $steps = StepTree::fromFiles($files)->standards[0]->tracks[0]->steps;
    $this->assertSame('first_of_all', $steps[0]->slug);
    $this->assertSame(0.0, $steps[0]->position);
}

public function testRefusesAStepFileWithNoNumber(): void
{
    $files = self::GOOD + ['template/rector/no_number.md' => self::GOOD[self::STEP]];
    $this->refuses($files, '#template/rector/no_number\.md.*<number>_<name>\.md#');
}

public function testRefusesAStepFileWithFiveDecimals(): void
{
    $files = self::GOOD + ['template/rector/1.00001_too_fine.md' => self::GOOD[self::STEP]];
    $this->refuses($files, '#1\.00001_too_fine\.md#');
}

public function testRefusesAPositionLineInAStepFile(): void
{
    $files = self::GOOD;
    $files[self::STEP] = str_replace("status: proven\n", "status: proven\nposition: 10\n", $files[self::STEP]);
    $this->refuses($files, '#10_rector_php_8_5_rules\.md.*position#');
}

public function testRefusesTheSameStepUnderTwoNumbers(): void
{
    $files = self::GOOD + ['template/rector/20_rector_php_8_5_rules.md' => self::GOOD[self::STEP]];
    $this->refuses($files, '#rector_php_8_5_rules.*already used#');
}
```

In `StandardsScanPayloadTest` line 31, the fixture path becomes `template/phpstan/100_phpstan_level_10_no_baseline.md` and its content loses its `position:` line. Results in that test keep the step name `phpstan_level_10_no_baseline`.

- [ ] **Step 2: Run and see them fail**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`
Expected: FAIL; the first is `"10_rector_php_8_5_rules" is not a valid step name`.

- [ ] **Step 3: Implement** in `StepTree.php`

- In `fromFiles()`, a file in a track that is not `_track` is a step only when `Names::stepFile($name)` is not null; otherwise throw `StandardsFormatException("{$path}: a step file is named <number>_<name>.md, like 030_autoloader.md")`. Key `$stepFiles` by the step name and carry the position with the path.
- `step()` takes the position as a parameter; `position` leaves its required-keys list, so a leftover line hits the existing `unknown frontmatter key "position"` refusal.
- `position()` stays for tracks only; say so in its comment.
- Class comment: the step line reads `<standard>/<track>/<number>_<step>.md  frontmatter: title, status, whose_move, check, param (optional)`, plus one sentence that the number is the position (0 to 65535.9999).

- [ ] **Step 4: Run and see them pass**

Run the three gates. Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add classes/Inventory/Standards/StepTree.php Tests/Unit/StandardsStepTreeTest.php Tests/Unit/StandardsScanPayloadTest.php
git commit -m "Read a step's position from its filename" -m "The order now lives in one place. A step file with a position line, or with no number, is refused so the two can never disagree." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 3: The scanner reports the name without the number

**Files:**
- Modify: `tools/site-standards/scan.py` (`checks_for`, near line 185)
- Test: `tools/site-standards/tests/test_scan_target.py`

**Interfaces:**
- Produces: `scan.step_name(stem: str) -> str`: the name after the number, or `stem` unchanged when it is not `<number>_<name>` (mg's importer is the judge of a malformed tree; the scanner only needs a name to report).

- [ ] **Step 1: Write the failing tests** (new `unittest.TestCase` in `test_scan_target.py`, importing `scan` the way the file already does)

```python
class StepNameTest(unittest.TestCase):
    def test_the_number_is_dropped(self):
        self.assertEqual(scan.step_name("030_autoloader"), "autoloader")
        self.assertEqual(scan.step_name("038.9996_remove_unused_print_robs"), "remove_unused_print_robs")
        self.assertEqual(scan.step_name("000_phpstan_level_0"), "phpstan_level_0")
        self.assertEqual(scan.step_name("05_composer_platform_pinned"), "composer_platform_pinned")

    def test_anything_else_is_left_alone(self):
        for stem in ("autoloader", "10_5_foo", "10.5.1_foo", "1.00001_foo", "1._foo", "10_", "10_Foo"):
            self.assertEqual(scan.step_name(stem), stem)

    def test_checks_for_reports_the_name(self):
        files = {"std/track/038.9996_a_step.md": "---\ncheck: echo\n---\n"}
        self.assertEqual(scan.checks_for("std", files), [("a_step", "echo", "")])
```

Change the existing fixture at line 119 to `"std/track/10_a_step.md"`; its expected result keeps `"step": "a_step"`.

- [ ] **Step 2: Run and see them fail**

Run: `python3 -I -m unittest discover -s tools/site-standards/tests`
Expected: FAIL, `module 'scan' has no attribute 'step_name'`.

- [ ] **Step 3: Implement** `step_name` with `re.match(r"\A\d+(?:\.\d{1,4})?_([a-z][a-z0-9_]{0,63})\Z", stem)` and call it in `checks_for`. Update the module docstring's line about step names.

- [ ] **Step 4: Run and see them pass**

Run: `python3 -I -m unittest discover -s tools/site-standards/tests`
Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add tools/site-standards/scan.py tools/site-standards/tests/test_scan_target.py
git commit -m "Scanner: report a step by its name, not its number" -m "mg matches results on the step name, which no longer includes the sort number in the filename." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 4: Rob's renames, and the real files load

**Files:**
- Rename (already done in the working tree, uncommitted): 69 files under `tools/site-standards/steps/template/*/`
- Modify: the same 69 files (remove `position:`)
- Create: `Tests/Unit/StandardsRealStepFilesTest.php`

**Interfaces:**
- Consumes: `StepTree::fromFiles(array<string, string>)`

- [ ] **Step 1: Record the names before** (HEAD still has the old filenames)

```bash
git ls-files tools/site-standards/steps | grep -v '/_' | sed 's#.*/##; s#\.md$##' | sort > "$SCRATCH/names-before.txt"
wc -l < "$SCRATCH/names-before.txt"
```

Expected: `69`. (`$SCRATCH` is the session scratchpad directory.)

- [ ] **Step 2: Commit the renames exactly as Rob made them**

```bash
git add -A tools/site-standards/steps
git status --short tools/site-standards/steps | grep -vc '^R '
```

Expected: `0` (69 lines, all `R`, pure renames). If any line is not `R`, stop and ask Rob. Then:

```bash
git commit -m "Number the step files in the order they apply" -m "Rob's renames: a directory listing now shows each track's steps in order. The number is the step's position; the name after it is unchanged." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

- [ ] **Step 3: Write the real-files test**

`Tests/Unit/StandardsRealStepFilesTest.php`: one test, `testTheStepFilesInThisRepoLoad`, that reads every `*.md` under `tools/site-standards/steps/` into a `path relative to that directory => contents` array, calls `StepTree::fromFiles()`, and asserts the tree has at least one step. Find the directory relative to `__DIR__`. If the Unit container cannot see `tools/`, stop and report; do not move the test to another suite.

- [ ] **Step 4: Run and see it fail**

Run: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`
Expected: FAIL with `unknown frontmatter key "position"`.

- [ ] **Step 5: Remove `position:` from the step files**

```bash
sed -i '/^position:/d' tools/site-standards/steps/*/*/[0-9]*.md
grep -l '^position:' tools/site-standards/steps/*/*/[0-9]*.md | wc -l    # 0
grep -L '^position:' tools/site-standards/steps/*/*/_track.md | wc -l    # 0: every track keeps its line
git diff --stat | tail -1                                                # 69 files changed, 69 deletions(-)
```

- [ ] **Step 6: Run and see it pass; check no name changed**

Run the three gates. Expected: all pass. Then:

```bash
git ls-files tools/site-standards/steps | grep -v '/_' | sed 's#.*/##; s#\.md$##; s#^[0-9.]*_##' | sort | diff - "$SCRATCH/names-before.txt" && echo SAME
git ls-files tools/site-standards/steps | grep -vc '/_'
```

Expected: `SAME` and `69`. The count matters because `.gitignore` ignores `php*`.

- [ ] **Step 7: Commit**

```bash
git add tools/site-standards/steps Tests/Unit/StandardsRealStepFilesTest.php
git commit -m "Drop position lines; the filename has the number" -m "phpstan_level_0 moves from 0.1 to 0, as its filename says. Every other file already agreed. A unit test now loads the real step files, so a misnamed one fails before deploy." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 5: Say how a step file is named

**Files:**
- Modify: `CLAUDE.md` (Site standards section, the "A step is a Markdown file" bullet)
- Modify: anything found by `grep -rn "position" tools/site-standards/*.md tools/site-standards/steps/README* docs/*.md 2>/dev/null` that tells a writer to add a `position:` line to a step (not specs or plans under `docs/superpowers/`, which are history)

- [ ] **Step 1: Rewrite the bullet.** It must say: the path is `tools/site-standards/steps/<standard>/<track>/<number>_<step>.md`; the number is the position, may have a dot and up to four decimals (`038.9996_`), and 0 is allowed; the step's name is the part after the number and is what results and `<step>.<shortcode>` issues are matched on, so renumbering a file never orphans anything while renaming the part after the number does; a step file has no `position:` line; `_track.md` still has one.

- [ ] **Step 2: Check `/next-mg-step`.** Read `~/.claude/skills/next-mg-step/SKILL.md`; if it builds a step file path from a step name, change it to find the file by `*_<name>.md` and commit that in `~/.claude`. If it does not, leave it.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "CLAUDE.md: a step file is <number>_<name>.md" -m "Tells the next writer where the order lives and which part of the filename must never change." -m "Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

- [ ] **Step 4: Final check and hand over**

Run the three gates and the Python tests once more; `git status --short` is empty. Tell Rob the bubble is ready for `git close-bubble`, and give him these two notes:

1. **After he deploys:** `tools/site-standards/scan.sh --dry-run mg` should retire no step that has a file on the deployed branch, and report the same step names as before.
2. **Other branches:** mg has 72 live steps and this branch has 69 step files, so some deployed steps come from branches not merged here. Any branch with step files written the old way (no number, a `position:` line) is refused once merged with this one; each such file needs renaming to `<its position>_<its name>.md` and its `position:` line removed. `StandardsRealStepFilesTest` names the first offender.
