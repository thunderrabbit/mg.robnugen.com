# /next-mg-step: a script for the lookup, a named model for the work

2026-11-14

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
3. The step's work runs in a subagent on that model. The session that Rob
   typed `/next-mg-step` into only runs the script, starts the subagent and
   relays.
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
instead of the work as it happens.

## Design

### 1. A step names its model

- A step file may carry `model:` in its frontmatter. A `_track.md` may carry
  `model:` as the default for its steps.
- Allowed values: `haiku`, `sonnet`, `opus`, `fable` (the names the Agent
  tool takes). A new enum, `Inventory\Standards\StepModel`.
- `StepTree` resolves each step's model: its own line, else its track's.
  An agent step that resolves to none is a `StandardsFormatException`, like
  any other malformed step file. A `whose_move: rob` step needs none and
  gets null.
- `StepDef` gains `?StepModel $model`. It reaches mg the way a step's title
  and how-to do: the scan sends it, `StandardsImporter` writes it to a new
  nullable column `std_steps.model` (`db_schemas/56_standards_step_model/`,
  which Rob applies), and `Board` reads it into `StepLine`. `SiteAnswer`'s
  open steps gain `"model": "sonnet"` (null for Rob's steps), so
  `GET /standards/site` shows it too. The step files stay the one source;
  nothing reads them from disk when answering.
- So a model line takes effect at the first scan after it is deployed, like
  any other change to a step file. Between applying the migration and that
  scan, agent steps have a null model. The script does not guess: it says
  "mg has no model for **`<title>`** yet; a scan will bring it."
  and stops.
- First values, for Rob to change in review. They are a starting guess; the
  point of the line is that changing one is a one-word commit.

  | Track | Agent steps | Model |
  |---|---|---|
  | codeception, foundations, groundwork, mysql, php_version, rector, tooling, javascript; wordpress/core | 27 | `sonnet` (track default) |
  | phpstan levels 0 to 5 | 6 | `sonnet` (track default) |
  | phpstan levels 6 to 10 | 5 | `opus` (on the step) |
  | mlaphp | 24 | `opus` (track default) |

  Tracks with no agent steps today (features, phpcs, security,
  wordpress/groundwork) get no line until they have one.

### 2. mg answers over ssh

A new `SiteCommand`, reached through the existing `site.sh`:

    tools/site-standards/site.sh next-step --checkout=<path under ~/work>
    tools/site-standards/site.sh next-step --shortcode=<code>

It prints one JSON object:

- `site`: exactly what `GET /standards/site` gives (`SiteAnswer`), or null.
- `shortcodes`: the list, when more than one site uses the checkout (then
  `site` is null).
- `server`: `ssh_alias`, `directory`, `upgrade_branch`, and `test_domain`
  for the site, as `site.sh sites` gives them today. These are private
  facts; they go over ssh only and never into the v1 API answer.

The handler in `wwwroot/api/v1/_standards.php` and this command build the
answer the same way; share the code that turns a checkout or shortcode into
a `SiteAnswer` so they cannot drift.

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
      "outcome": "say" | "ask" | "dispatch",
      "say":     "text for Rob, to be shown as it is",
      "ask":     "a question only Rob can answer",        (outcome ask)
      "model":   "sonnet",                                (outcome dispatch)
      "brief":   "the whole prompt for the subagent",     (outcome dispatch)
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
- **The check gets what the skill gives it today**: `SITE_PHP_CURRENT` and
  `SITE_TEST_PHP`, nothing more. The scanner gives checks more facts, so a
  few checks answer "could not tell" here and the step is dispatched with
  that line quoted. That is today's behaviour and stays; in particular a
  lookup must never run a site's safe suite. (My call.)
- **dispatch**: the first open step is the agent's and the check says it is
  not done here. `say` holds the "which step it is" lines, including the
  served PHP and any one-line notes from 1b and from a skipped scan.
- A check that exits 2 ("could not tell") still dispatches, with the
  check's line quoted in both `say` and `brief`.
- The server not answering ssh in 1b is quoted in `say` and does not stop
  the run, as today.

`--confirm` is for after the subagent returns: it repeats the lookup far
enough to re-run the step's check script and prints
`{"outcome": "say", "say": "<the check's line>", "after": "<scan reminder>"}`.
For a step whose check is one of mg's own (no script), it says so and gives
the reminder.

**The brief** is built from a template kept beside the script
(`tools/site-standards/next_step_brief.md`) so its wording is versioned and
the same on every run. It gives the subagent: the step's title, track,
detail, served PHP, explanation and how-to; the check command to run; and
the rules now in section 4 steps 3 to 6 of the skill:

- compare the how-to with this repo before changing anything;
- one step only, and never mark it done;
- work under this project's own rules. Read its `CLAUDE.md` and its memory
  index (the brief gives the path) and `git log` before the first commit;
  never commit on, check out or merge into the trunk;
- the Rector dry run before each commit, and Rector's form in the same
  commit when it differs from the how-to;
- finish by running the check and the project's gates;
- return one of three reports: **done** (what changed, commits, the check's
  line, gate results), **does not fit** (why; the session will suggest the
  NAh issue to Rob), or **needs Rob** (one question). A subagent cannot
  talk to Rob, so a step big enough to need his say on its shape comes back
  as "needs Rob" with a proposal, before any commit.

One sentence in the skill stays a judgement for now: after a `rob` step
whose how-to has an agent propose something, the offer "Want to work it
through together?". The script prints the how-to; the skill keeps that one
instruction. (My call, to keep this change from growing a new frontmatter
line; say if you want it scripted too.)

If Rob says yes and later picks a shape, the session does that work itself
(a taste step stays a conversation). So that the working rules still have
one home, the script's answer for a `rob` step carries a `rules` field: the
rules part of the same brief template. The skill says only "work under
`rules`".

### 4. The skill

`~/.claude/skills/next-mg-step/SKILL.md` becomes roughly:

1. Run `~/work/rob/mg.robnugen.com/tools/site-standards/next-step.sh`. If
   the file is not there, say so (mg's checkout is on a branch without it)
   and stop.
2. Show `say` as it is.
3. `ask`: put the question to Rob and stop.
4. `dispatch`: start one Agent with `model` and `brief`, unchanged. When it
   returns, relay its report. On **done**, run the script with `--confirm`
   and show its `say` and `after`. On **does not fit**, suggest the NAh
   issue. On **needs Rob**, put the question to Rob; his answer goes back
   to the same subagent.
5. End with `after` whenever the script gave one.

The two rules that are about tone stay in the skill in one line each: no
warning wording, and add nothing speculative after a stop.

### 5. Which copy runs

The script and the check scripts are read from the mg checkout's working
tree on Lemur 13, as the skill reads the checks today. The step files and
the answer come from mg's database, which a scan fills from the step files
mg has deployed. So a `model:` line takes effect at the first scan after
Rob deploys it, and the script works once the mg checkout is on a branch
that has it. Until mg has deployed the `next-step` command, the
script reports mg's refusal as an error from mg and stops.

## Testing

- Codeception Unit: `StepTree` (step model, track default, step overrides
  track, agent step with none is refused, unknown value is refused, Rob's
  step may have none), `SiteAnswer` carries `model`, `SiteCommand` parses
  `next-step`.
- Python unittest beside the others in `tools/site-standards/tests/`: one
  test per outcome of `next_step.py`, with mg's answer and the server's
  commit faked the way `test_scan_*.py` fake ssh. Each stop sentence is
  asserted word for word against the present skill.
- The real step files load under the new rule (the existing test that
  reads them all).

## Order of work

1. `StepModel`, `StepTree`, `StepDef`, migration 56, the scan document and
   `StandardsImporter`, `Board`, `StepLine`, `SiteAnswer`, with the model
   lines added to the step and track files in the same bubble (the rule
   and the lines cannot be deployed apart). The importer must accept a
   scan document with no model while an older scanner is still about.
2. `SiteCommand next-step`.
3. `next_step.py`, the brief template, tests.
4. `CLAUDE.md` paragraph.
5. After Rob deploys, applies migration 56 and runs a scan: the new skill file (in `~/.claude`, its own repo),
   then a live run. `mg_api_help()['standards']` in `~/jikan` gains the
   `model` field as a small follow-up.

## Left to the builder

- The exact JSON key names inside `server`, and how `SiteCommand` and the
  v1 handler share their code.
- How `next_step.py` is split into functions, and how ssh is faked in its
  tests.
- The wording of the brief, within the rules listed above.
