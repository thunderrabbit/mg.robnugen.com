# /next-mg-step: a script for the lookup, a named model for the work

2026-11-14, revised 2026-11-23 (see Revisions at the end)

## Why

`/next-mg-step` is Rob's way to make any site's agent do that site's first
open step on the standards page. Today the whole procedure is prose in
`~/.claude/skills/next-mg-step/SKILL.md`, read and carried out by whatever
model the session runs. Two things follow from that:

- The same call can behave differently from one run to the next, because a
  model re-reads about 200 lines of rules each time and applies them by
  judgement.
- `/usage` showed the skill at 17% of usage, more than the two biggest
  superpowers skills together, and suggested a cheaper model. The step's
  real work (a PHPStan level, an MLAPHP chapter) runs on the session's
  model, which is usually the most expensive one.

Rob wants repeatable behaviour. The saving matters too, but comes second.

## Goals

1. Everything in the skill that is a rule over data becomes a script. Given
   the same mg answer, server commit and checkout, it prints the same words.
2. Each agent step names the model that does it. The name is in the step
   files, so Rob sets it once and it is the same on every site.
3. A step named `haiku` or `sonnet` runs in a subagent on that model. The
   session that Rob typed `/next-mg-step` into only runs the script, starts
   the subagent, verifies and relays. A step named `opus` or `fable` is done
   by the session itself, under the script's rules, where Rob can watch and
   interrupt.
4. The skill file shrinks to a short dispatcher with no rules of its own to
   interpret.

## Non-goals

- Changing which step is next, what counts as done, or any check.
- Changing the model of the session itself. No `model:` line in the skill's
  frontmatter.
- Making the taste steps (`whose_move: rob`, where an agent proposes and Rob
  picks) run in a subagent. They stay a conversation in the session.
- Scheduling, or running a step without Rob typing the command.

## Anti-goals

- A default model. A step with no model named must be refused when the step
  files are read, never quietly run on something.
- A second copy of the rules. Once the script holds a rule, the skill must
  not restate it.
- Site names, shortcodes, domains or aliases in anything committed to mg
  (the repo is public). Tests use `mg` or made-up names.

## Approaches considered

1. **`model:` in the skill's frontmatter.** One line. Every step then runs
   on one model, whatever the step, and the rules are still prose.
2. **Fork the whole skill into a pinned-model subagent** (`context: fork`).
   The model is fixed, but the skill's many "say this to Rob and stop"
   points become a report from a subagent, and the rules are still prose.
3. **Script the lookup; mg names a model per step; a subagent does the
   work.** Chosen by Rob on 2026-11-14. It is the only one that makes the
   behaviour repeatable, and the only one that lets a mechanical step and a
   restructuring step run on different models.

Within 3, Rob chose a subagent on the named model over the script merely
advising a model: enforced beats advised, at the cost of seeing a report
instead of the work as it happens. On 2026-11-23 he narrowed that to the
cheap models: a subagent on `opus` saves nothing over the session and loses
the chance to redirect mid-work, and those are the steps (MLAPHP, PHPStan 6
to 10) where he does redirect.

## Design

### 1. A step names its model

- A step file may carry `model:` in its frontmatter. A `_track.md` may carry
  `model:` as the default for its steps. Both key lists are hard-coded in
  `StepTree` and gain the key.
- Allowed values: `haiku`, `sonnet`, `opus`, `fable`. A new enum,
  `Inventory\Standards\StepModel`. These are the names the Agent tool's
  `model` parameter takes today; that list can change, and then the enum
  changes with it.
- `StepTree` resolves each step's model: its own line, else its track's.
  An agent step that resolves to none is a `StandardsFormatException`, like
  any other malformed step file. A `whose_move: rob` step needs none and
  gets null. `StepDef` gains `?StepModel $model`.
- **The model is not stored in mg's database.** No column, no migration, no
  change to the importer, `Board`, `StepLine`, `SiteAnswer` or the v1 API.
  mg already keeps the commit whose step files it last imported
  (`std_scans.steps_commit`). The `next-step` command (section 2) returns
  it, and the script reads the step's `model:` line (else its track's) from
  that commit of the mg checkout with git, the way `scan.py` reads step
  files. The step files stay the one source.
- So a model line takes effect at the first scan after it is deployed, like
  any other change to a step file. If the scanned commit has no model for
  an agent step (a scan from before the lines existed), or the mg checkout
  does not have that commit, the script does not guess: it says
  "mg's last scan has no model for **`<title>`**; a scan will bring it."
  and stops.
- Python reads only the one `model:` line; `StepTree` in PHP stays the one
  place that says whether a step file is well formed.
- First values, for Rob to change in review. They are a starting guess; the
  point of the line is that changing one is a one-word commit.

  | Track | Model |
  |---|---|
  | codeception, features, foundations, groundwork, mysql, php_version, rector, tooling, javascript; wordpress/core | `sonnet` (track default) |
  | phpstan levels 0 to 5 | `sonnet` (track default) |
  | phpstan levels 6 to 10 | `opus` (on the step) |
  | mlaphp | `opus` (track default) |

  A track with no agent steps gets no line until it has one.

### 2. mg answers over ssh

A new `SiteCommand`, reached through the existing `site.sh`:

    tools/site-standards/site.sh next-step --checkout=<path under ~/work>
    tools/site-standards/site.sh next-step --shortcode=<code>

It prints one JSON object:

- `site`: exactly what `GET /standards/site` gives (`SiteAnswer`), or null.
- `shortcodes`: the list, when more than one site uses the checkout (then
  `site` is null).
- `facts`: the site's own object exactly as `site.sh sites` prints it
  today (`server`, `test`, `upgrade_branch`, `php_current` and the rest),
  so `scan.py`'s `check_environment` reads it unchanged. Null when `site`
  is null. These are private facts; they go over ssh only and never into
  the v1 API answer.
- `steps_commit`: the commit of the latest scan's step files.

The handler in `wwwroot/api/v1/_standards.php` and this command build the
answer the same way; share the code that turns a checkout or shortcode into
a `SiteAnswer` so they cannot drift. Today the v1 lookup is inline in the
handler, so that means a new class both call. `SiteCommand::run` takes only
a `SiteStore` today; `next-step` also needs the `Board`, so its signature
and `tools/site_standards.php` change.

Why ssh and not the v1 API: the skill already needs `site.sh sites` over
ssh for the server facts, every agent on Lemur 13 can `ssh mg`, and a
script has no jikan MCP to call.

### 3. The script

`tools/site-standards/next-step.sh [--shortcode=<code>] [--confirm]`, a
thin wrapper over `next_step.py` like `scan.sh` over `scan.py`. Run from
inside the site's checkout. It never writes to any repo and never changes
anything on mg.

It carries out, in the skill's present order and with the skill's present
sentences word for word: section 1 (the repo's path), section 2 (ask mg),
section 3 (read the answer), section 4 steps 1, 1b and 2 (say which step,
the second-line question, run the check here).

It prints one JSON object on stdout and exits 0 whenever it could decide
(non-zero only when the script itself broke):

    {
      "outcome": "say" | "ask" | "dispatch" | "do",
      "say":     "text for Rob, to be shown as it is",
      "ask":     "a question only Rob can answer",        (outcome ask)
      "model":   "sonnet",                                (outcome dispatch, do)
      "brief":   "the whole prompt for the subagent",     (outcome dispatch)
      "rules":   "the working rules, for the session",    (outcome do, and a rob step)
      "base":    "<commit HEAD was at>",                  (outcome dispatch, do)
      "after":   "the scan reminder, shown last"          (when a step was done or found done)
    }

- **say**: every full stop the skill has today. Not under `~/work`, mg
  unreachable, an error from mg, site not on the page, up to date, nobody
  can reach it (with Rob's steps and their how-tos), the first step is
  Rob's (title, detail, how-to), already done here.
- **ask**: more than one site uses the checkout (which shortcode?), and the
  second-line case (the server runs commits this branch lacks and
  `upgrade_branch` is not this branch: may I set it?). The script never
  runs `site-set` itself. Rob answers; the session runs the script again
  (with `--shortcode=`, or after `site-set`).
  If Rob says no to setting `upgrade_branch`, the session runs the script
  again with `--leave-upgrade`, which skips that question and goes on to
  the step, as the skill does today.
- The stops that were about jikan get new sentences, since the script
  talks to mg over ssh (my wording; the tests pin them):
  - ssh to mg fails: "I can't reach mg from here (`<ssh's error line>`),
    so /next-mg-step can't look up the step."
  - mg refuses the command: "mg refused the lookup: `<mg's sentence>`"
  Every other sentence is the skill's present one, word for word.
- **The server is asked the way the scanner asks it**:
  `server_facts.head_and_branch`, which tries a server's forced-command
  facts host first. The skill's raw `ssh <alias> git rev-parse` does not
  work for every server.
- **The check gets the scanner's environment**: `scan.py`'s
  `check_environment`, built from the same facts, so a check answers here
  what it will answer in the scan. With only the skill's two variables,
  `suite_passes_on_php` sees no test domain and passes, and the script
  would say "Already done here" for a step that is not.
- **A lookup never runs a site's safe suite.** `suite_passes_on_php` is not
  run here; the script treats it as "could not tell: only the scan runs a
  site's safe suite". The checks a lookup must not run are a named list in
  `next_step.py` with that one entry, and a test pins it. (My call.)
- `check_param` arrives from mg already replaced for a `target` step, and
  null for a site with no target; the script passes nothing then.
- **dispatch**: the first open step is the agent's, the check says it is
  not done here, and its model is `haiku` or `sonnet`. `say` holds the
  "which step it is" lines, including the served PHP and any one-line notes
  from 1b and from a skipped scan.
- **do**: the same, for a step whose model is `opus` or `fable`. The
  session does the step itself under `rules`, as a taste step is done once
  Rob has picked. If the session runs on a cheaper model than the step
  names, `say` ends with one line saying which model the step asks for; the
  session goes on. (My call: the script cannot change the session's model,
  and stopping would make the command useless in such a session.)
- A check that exits 2 ("could not tell") still dispatches (or does), with
  the check's line quoted in `say` and in `brief` or `rules`.
- The server not answering in 1b is quoted in `say` and does not stop the
  run, as today.

**`--confirm --base=<commit>`** is for after the work, whoever did it. It
trusts no report. It prints `say` as a short list of facts it found itself,
`after` (the scan reminder), and `"clean": true|false`:

- the branch is not the trunk, and the working tree is clean;
- the commits in `base..HEAD`; when there are four or more, that the first
  is a `BEGIN ` commit touching one file;
- the step's check, re-run, and its line (for one of mg's own checks, that
  it has no script);
- the project's gates, run by the script: each of
  `standards-mcp/run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh` that the
  repo is set up for, with its exit code;
- in the diff `base..HEAD`: lines added that hold `@phpstan-ignore`, any
  baseline file added or grown, test files deleted, and tests newly marked
  skipped or incomplete.

`clean` is true when the branch and tree are right, the check passed (or
has no script), every gate that ran passed, and the last group is all
zeroes. The counts are facts for Rob, not a verdict on the work: a
legitimate `@phpstan-ignore` makes `clean` false, and the session then
shows the facts and leaves the bubble for Rob.

**The brief and the rules** are built from templates kept beside the script
(`tools/site-standards/next_step_brief.md`) so their wording is versioned
and the same on every run. `rules` is the rules part of the brief. The
brief gives the subagent: the step's title, track, detail, served PHP,
explanation and how-to; the check command to run; and the rules now in
section 4 steps 3 to 6 of the skill:

- compare the how-to with this repo before changing anything;
- one step only, and never mark it done;
- work under this project's own rules. A subagent is given `CLAUDE.md` and
  the memory index but not the memory files: open every memory file the
  index links about commits, bubbles, branches and gates, and read
  `git log`, before the first commit; never commit on, check out or merge
  into the trunk;
- open a bubble when the project's rules call for one, and **leave it
  open**: never run `git close-bubble`, never push;
- wherever a project rule says "ask Rob" (a bubble of exactly three
  commits, where to branch, a step big enough to need a plan), stop and
  return "needs Rob"; a subagent cannot talk to him;
- the Rector dry run before each commit, and Rector's form in the same
  commit when it differs from the how-to;
- finish by running the check and the project's gates;
- give times as absolute timestamps or not at all (no hook tells a
  subagent the time);
- return one of three reports: **done** (what changed, each commit's hash,
  the check's line, each gate command and its exit code), **does not fit**
  (why; the session will suggest the NAh issue to Rob), or **needs Rob**
  (one question, with a proposal, before any commit).

The templates are committed to a public repo: the skill's examples that
name a real site are rewritten with `mg` or a made-up name.

One sentence in the skill stays a judgement for now: after a `rob` step
whose how-to has an agent propose something, the offer "Want to work it
through together?". The script prints the how-to; the skill keeps that one
instruction. (My call, to keep this change from growing a new frontmatter
line; say if you want it scripted too.)

If Rob says yes and later picks a shape, the session does that work itself
(a taste step stays a conversation) under the `rules` the script gave.

### 4. The skill

`~/.claude/skills/next-mg-step/SKILL.md` becomes roughly:

1. Run `~/work/rob/mg.robnugen.com/tools/site-standards/next-step.sh`. If
   the file is not there, say so (mg's checkout is on a branch without it)
   and stop.
2. Show `say` as it is.
3. `ask`: put the question to Rob and stop.
4. `dispatch`: start one Agent with `model` and `brief`, unchanged. When it
   returns, relay its report.
   - **done**: go to 6.
   - **does not fit**: suggest the NAh issue and stop.
   - **needs Rob**: put the question to Rob. His answer goes back to the
     same subagent (SendMessage; load it if it is deferred). If that
     subagent is gone (a new session, a `/clear`), start a fresh one with
     the brief, the proposal and Rob's answer.
5. `do`: do the step yourself under `rules`, leaving any bubble open.
6. Run the script with `--confirm --base=<base>` and show its `say`. If
   `clean` is true and the work opened a bubble, close it by the project's
   rules (the `close-bubble` skill). If not, leave everything as it is and
   say the bubble is open for Rob. Never push.
7. End with `after` whenever the script gave one.

The two rules that are about tone stay in the skill in one line each: no
warning wording, and add nothing speculative after a stop.

Before the skill is switched over, try one `sonnet` step live to see
whether a background subagent's permission prompts (docker for the gates)
reach Rob. If they are refused unseen, the gates run only in `--confirm`
and the brief says so.

### 5. Which copy runs

The script and the check scripts are read from the mg checkout's working
tree on Lemur 13, as the skill reads the checks today. The step files and
the answer come from mg's database, which a scan fills from the step files
mg has deployed; the model comes from the mg checkout's git, at the commit
that scan read. So a `model:` line takes effect at the first scan after
Rob deploys it, and the script works once the mg checkout is on a branch
that has it. Until mg has deployed the `next-step` command, the
script reports mg's refusal as an error from mg and stops.

## Testing

- Codeception Unit: `StepTree` (step model, track default, step overrides
  track, agent step with none is refused, unknown value is refused, Rob's
  step may have none), `SiteCommand` parses `next-step` and its answer
  carries `steps_commit` and the site's facts, and the class the v1 handler
  and the command share.
- Python unittest beside the others in `tools/site-standards/tests/`: one
  test per outcome of `next_step.py`, with mg's answer and the server's
  commit faked the way `test_scan_*.py` fake ssh. Each stop sentence is
  asserted word for word against the present skill. `--confirm` against a
  scratch git repo: each fact, and each thing that makes `clean` false.
- The real step files load under the new rule (the existing test that
  reads them all).

## Order of work

1. `StepModel`, `StepTree`, `StepDef`, with the model lines added to the
   step and track files in the same bubble (the rule and the lines cannot
   be deployed apart). Nothing reads the model yet.
2. The shared lookup class, and `SiteCommand next-step`.
3. `next_step.py` (lookup, then `--confirm`), the brief template, tests.
4. `CLAUDE.md` paragraph.
5. After Rob deploys and runs a scan: the new skill file (in `~/.claude`,
   its own repo), then a live run on a `sonnet` step. The old skill keeps
   working until then, since nothing it uses changes.

## Left to the builder

- The exact JSON key names inside `server`, and how `SiteCommand` and the
  v1 handler share their code.
- How `next_step.py` is split into functions, and how ssh is faked in its
  tests.
- The wording of the brief, within the rules listed above.

## Revisions, 2026-11-23

The spec sat uncommitted for nine days. Two reviewing agents read it against
the code and against how subagents behave; Rob picked among their changes.

- **Subagent for cheap models only** (Rob). `opus` and `fable` steps are
  done by the session: outcome `do`.
- **No database column** (Rob). The model is read from the step files at
  the scanned commit. The original design added `std_steps.model`, a
  migration, and changes to the importer, `Board`, `StepLine`, `SiteAnswer`
  and the v1 API, to carry one word from a file to a script on the machine
  that holds the file; deploying it before the migration would have stopped
  every scan. It also assumed the scan document carries a field per step;
  it carries the raw step files.
- **`--confirm` verifies for itself, and the session closes the bubble**
  (Rob). The original re-ran only the step's check and did not say who
  closes.
- The check runs in the scanner's environment and the server is asked
  through `server_facts` (both were wrong for some sites); a lookup never
  runs a safe suite.
- The brief carries what a subagent is not given, and turns every "ask Rob"
  into "needs Rob". The templates name no real site.
- `features` has agent steps now and gets a model line.
