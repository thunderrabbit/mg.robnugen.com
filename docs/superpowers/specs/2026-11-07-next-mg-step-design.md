# Standards steps in the API, and `/next-mg-step` (mg #806)

## Why

`/admin/dh/standards.php` tells Rob that a site has "1 step to go", but the
agent who owns that site can't see which step it is. The page is for the
account owner's browser only, and no API exposes the standards. Today the only
way to find out is to query the database over ssh.

Rob wants to say one precise thing to an agent and have it do the work:

> `/next-mg-step`

It has to be precise because "next step" already means something else in
Rob's sessions: carrying on with a saved plan after `/clear`.

## What it does

`/next-mg-step` is a global skill in `~/.claude/skills/next-mg-step/`, so
every agent has it, whatever repo it runs in.

1. **Look up the step deterministically.** One `mg_api` call asks mg about the
   agent's own repo. Choosing the step is not the LLM's job: mg answers with
   the site's open steps in the page's order, and the skill takes the first.
2. **Stop and report plainly** when the answer is any of these:
   - **No site.** The repo isn't on the standards page. Example: an agent
     whose site runs on EC2 rather than DreamHost has an mg project, but its
     repo is not a site. Report it in one sentence, such as "This repo isn't
     on mg's standards page, so `/next-mg-step` has nothing for it," and stop.
     Don't guess at steps, and don't offer to add the site.
   - **All done.** Report "up to date" and stop.
   - **An error**, such as a 403, a 5xx or a malformed answer. Quote the error
     and stop.
3. **If the first open step is Rob's** (`whose_move: rob`), tell Rob what to do
   using the step's title and how-to, then stop. Don't skip ahead to a later
   step the agent could do.
4. **If the first open step is the agent's,** read its explanation and how-to,
   compare them carefully with the code, and apply the step. Follow the
   project's own git rules from its memory, `CLAUDE.md` and `git log`: BEGIN
   commit, bubble sizing, branch naming, standards gates. Brainstorm or write
   a plan first when the step is big enough to warrant one.
5. **End with the reminder.** The skill never marks a step done; the scan
   does, from deployed code. Its last words to Rob are what to approve and
   deploy, then the exact scan command:

       ~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh <shortcode>

   `scan.sh` already takes shortcodes and scans only those sites.

## Goals

- One phrase, `/next-mg-step`, that any agent understands the same way.
- The step is chosen by mg, so the page and the agent always agree on which
  step is next.
- A repo that isn't a site gets a plain no, never an invented step.

## Non-goals

- Marking steps done from the agent or the API. A check ticks a step, run by
  the scanner against the code the server runs (unchanged from the
  2026-11-06 design).
- Adding sites, or writing any standards data, through the API. Site facts
  are still entered with `site.sh`.
- Doing more than one step per call.
- Changing `scan.sh`.
- A jikan MCP tool of its own. `mg_api` is the transport.

## Anti-goals

- The page says one thing and the endpoint another. Both must come from the
  same rules (`Board` / `SiteReport` / `SiteStanding`), not a second copy.
- An agent "finishing" a step that is only on a local branch, and something
  then showing it as done.
- Leaking the hosting map. Domains, DreamHost usernames, ssh aliases and
  server paths stay off the API answer.
- Warning styling or alarm wording in anything the skill says. Every site is
  working.

## The endpoint

    GET /api/v1/standards/site?checkout_path=<path relative to ~/work>

The skill computes `checkout_path` from `git rev-parse --show-toplevel` with
the `~/work/` prefix stripped (for example `rob/example.robnugen.com`). That
is the form `std_sites.checkout_path` already holds. The agent doesn't store
its shortcode anywhere; mg's answer tells it.

`?shortcode=<code>` is accepted instead, for Rob or an agent asking about a
named site. If both are given, it's a 400.

**Who may call it.** Like `AdminPage::guard`, these tables are for user 1
only. An actor whose `user_id` isn't 1 gets 403. The actor also needs
`can_read_project`, checked the same way `require_project_perm` checks it.
Limited actors never reach it, because `Guards::limitedRoute` doesn't list
it. Read only: any other method gets 405.

**Answers** (all 200 unless noted):

No site matches:

```json
{ "site": null }
```

A site:

```json
{
  "site": {
    "shortcode": "example",
    "standard": "template",
    "standing": { "mark": "info", "text": "1 step to go" },
    "last_checked_utc": "2026-11-07 03:12",
    "skipped_reason": null,
    "open_steps": [
      {
        "slug": "rector_type_declarations",
        "track": "Rector",
        "title": "Declare the types Rector can prove",
        "whose_move": "agent",
        "detail": "rector.php does not apply the type-declaration set",
        "check": "rector_types",
        "check_param": null,
        "explanation": "…",
        "how_to": "…"
      }
    ]
  }
}
```

- `open_steps` lists the site's proven steps that aren't done, in the page's
  display order. "Done" means what it means on the page: the check passed, or
  a site note in status NAh. It's `[]` when the site is up to date.
- `standing` is `SiteStanding::of`'s mark and text, word for word. The skill
  may quote `text`.
- `skipped_reason` is the latest scan's reason for skipping this site, if it
  skipped. Then `open_steps` reflects an older scan, and the skill says so.
- A malformed `checkout_path` or `shortcode` gets a 400 with `{error}`.
- **Two sites, one checkout** (my call). `checkout_path` isn't unique in
  `std_sites`, though no two sites share one today. If more than one site
  matches, the answer is 409 with
  `{error, shortcodes: [...]}` asking the caller to pass `?shortcode=`. The
  database's row order must never pick the site. There's no migration: a
  shared checkout may be legitimate later.

The endpoint is documented in `wwwroot/api/v1/openapi.yaml`, so
`mg_api_help` shows it.

## Building it (builder's calls unless stated)

- `Board` gains a lookup for one site, by shortcode or by checkout path,
  returning the same `site()` array the page uses, or null. `SiteReport` /
  `StepLine` already carry everything in the answer except check name and
  param. Add those to `StepLine` rather than querying them a second time.
- A new handler, `wwwroot/api/v1/_standards.php`, routed from `index.php`
  like the others. It names `index.php`'s variables in a `@var` block.
- Unit tests cover the JSON shaping from a `SiteReport` built from plain rows,
  the no-site answer, and the order of `open_steps`. The user-1 gate gets a
  test if the existing guard tests make that cheap.
- The skill's `SKILL.md` holds the five-part procedure above, the stop
  wordings, and the scan reminder. It's committed in `~/.claude`, in a
  separate commit from mg's code.

## What the skill does, in a little more detail (my calls)

- **Confirming the work.** When the step's `check` is a script in
  `tools/site-standards/checks/`, the agent runs it on its own working tree
  before starting and again when it's done:
  `~/work/rob/mg.robnugen.com/tools/site-standards/checks/<check>.sh <repo> [param]`.
  Exit 0 before starting means the step is already done on a branch that
  isn't deployed yet. Then the agent tells Rob that and stops, instead of
  redoing it. Checks that mg answers from its own tables (`dh_*`,
  `repo_located`) aren't scripts and are skipped.
- **A step that doesn't fit the site.** The agent doesn't decide this alone.
  It stops and suggests Rob mark the step NAh: an issue titled
  `<step>.<shortcode>` in the site's project, set to NAh.
- **The latest scan skipped the site** (`skipped_reason` set). The skill
  carries on, but first tells Rob the open steps come from the scan of
  `last_checked_utc` and quotes the reason. The check-script pre-run above is
  the guard against redoing a step that's already done on an undeployed
  branch. Where no script exists, the agent compares the code before
  changing anything.
- **Nobody can reach the site** (the face). The skill stops and gives Rob the
  site's open steps that are his. They come first anyway: Groundwork is the
  first track, and its first three steps (domain, ssh login, repo located)
  are `whose_move: rob`. The skill checks `standing.mark === "face"` so it
  doesn't depend on that order.
- **`autonomy`** isn't in the answer. Nothing reads it yet (2026-11-06
  design), and every site is `paired`, so the agent works the way it already
  does with Rob.

## Order of work

1. This design (committed in the #806 bubble).
2. The endpoint, its tests and openapi, in mg's #806 bubble.
3. Rob deploys. The skill is written against the live endpoint.
4. The skill, committed in `~/.claude`.
5. Rob practises `/next-mg-step` on mgClaude. mg's open Rector step is the
   first real run.
