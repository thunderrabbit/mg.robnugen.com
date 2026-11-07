# Proving a site's code before the PHP version moves

Date: 7 Nov 2026. Rob, with Claude (brainstormed in the mg session).

## Intent

On the standards page a site can reach "switch the domains to PHP 8.5" (Rob's
step) with nothing having proved the code runs on 8.5. Today the how-to for
`serves_php_8_5` only *advises* that the tooling and Rector steps be done first
and that the deployed tree be linted. No step makes an agent do that, and no check
proves it.

Rob's words for what is wanted: protect every site from code that "won't compile"
on the new PHP, and for sites with a test domain, "ensure the full test suite
passes" there before production moves. The practice must work for every future
target, "especially when we jump from PHP 8 to 9", so the target version is a
parameter and never baked into the design.

## Goals

- For every site, before Rob's panel switch, an agent step makes the code run on
  **both** the PHP the site serves now and the target PHP, and a check proves it.
- For a site with a test domain, Rob switches **only that domain** first, then an
  agent step runs the site's own full test suite against it.
- Adding a future target (8.6, 9.0) is copying a folder of step files and changing
  one parameter, not new code.

## Non-goals

- mg does not learn how any site tests. It knows only whether a test domain
  exists. Each repo's own test code handles itself.
- No step runs against production, and no check writes to a repo.
- Sites not on DreamHost, and the WordPress standard, are untouched.

## Anti-goals

- A site whose test backend is its live backend must never have the suite create
  real orders. "Full test suite" for such a site means the safe suite. That rule
  lives in the site's own repo (see Tier 2), never in mg, and the check must
  refuse to run a suite the repo has not declared safe.
- No step may introduce target-only syntax before the panel switch, or the
  deployed code would break during the gap between deploying and switching.

## The sequence, per target version X

1. **Tier 1, agent, every site: "Code runs on the current PHP and on X".** In
   Docker, with the target's runner image, run Rector and the site's other tools
   to fix what X removed or deprecated, using only changes that still run on the
   PHP the site serves today. The agent may change code.
2. **Rob, only if the site has a test domain: "Test domain serves X".** Rob
   switches that one domain in the panel. The check reads the latest sweep.
3. **Tier 2, agent, only if a test domain exists: "Full test suite passes on X".**
   The agent runs the repo's own suite against the test domain, now on X.
4. **Rob, every site (the existing step): "Serves X".** Every domain switched.
5. **After the switch, the existing Rector step** may modernise to X-only syntax.
   This is Claude's guess about where it belongs; Rob confirmed steps 1 to 4 only.

A site with no test domain goes 1 then 4, and the step text must say that this
is by design, so it is not read as skipped testing.

## What mg needs

- **One new fact.** A nullable `test_site_domain_id` on `std_sites`, a foreign key
  to `dh_site_domains` like the existing `server_site_domain_id`, set by hand with
  `tools/site-standards/site.sh site-set --test=<domain>`. "Has a test site" is
  "not null". (Today mg can tell production from the other domains only by the
  `--server=` pointer, which is ambiguous with more than one extra domain.)
- **A host check `dh_test_serves_php`** in `HostChecks`, answered from the sweep
  like `dh_serves_php`, looking at that one domain. A site with no test domain
  passes it with a line saying why, so the page shows no open work.
- **A Tier 1 check script** `checks/code_runs_on_php.sh REPO X`: lint every PHP
  file in the repo's scope on the target's runner image *and* on the image of the
  PHP the site serves now. Exit 0 only if both compile, 1 with the first failing
  file, 2 if a runner image or Docker is missing. Read-only, Docker only through
  the standards-mcp runners, as every check script is.
- **The current PHP reaches the check** from the sweep. The scanner already asks
  mg about the site; `scan.sh` passes it to the check, for example as an
  environment variable. Claude's call, to be settled in the plan.
- **A Tier 2 check** that the repo's declared safe suite passed. Convention
  (Claude's guess): the repo provides one script that runs only safe tests against
  its own test domain and exits 0 or 1. The check refuses to run if the script is
  missing, answering "could not tell" rather than inventing a suite.
- **Step files per target.** A track per target (`php_8_5/`, later `php_8_6/`)
  holding the steps above, each carrying the version as `param`. The existing
  `serves_php_8_5` and `rector_php_8_5_rules` move into or beside it, and the
  `serves_php_8_5` how-to is rewritten so it no longer tells Rob to "make sure the
  code is ready" by hand.

## Constraints

- This repo is public: step files, tests and comments use `mg` or made-up names.
- Only deployed step files count, and only a check ticks a step (see
  `CLAUDE.md`, "Site standards").
- Runner images must exist for the current and target versions in standards-mcp
  (`runner-image.sh`); a missing image is "could not tell", never a pass.
- Check lines are one line of at most 255 characters.

## How Tier 2 runs (Rob's decision, 7 Nov 2026)

The scanner on Lemur 13 runs the repo's declared safe-suite script itself, in a
container that has network, like `run-webdriver.sh`. The check ticks only from a
real run against the current commit. Rob's constraints: **tests run only when
necessary**, because a suite run for every site would make one scan take over an
hour, and **it is automatic**: Rob does not name sites or flag anything, as for
every other step.

The scanner runs a site's suite only when all of these hold, and otherwise
answers "could not tell" with a line saying why the suite was not run, never a
pass:

1. the site has a test domain;
2. that domain already serves X in the latest sweep (Rob's panel switch is the
   trigger);
3. the site's Tier 1 check passes;
4. the checkout's commit differs from the one the last suite result (pass or
   fail) was recorded for, so an unchanged commit is never run twice.

Only the one or two sites in this state at any moment run a suite, so a plain
`scan.sh` stays fast.

**The agent's own test runs are unchanged.** Rob and an agent keep running the
site's tests through the existing Docker harness (standards-mcp's runners,
`check_tests`, the Webdriver runner) during development. The repo's declared safe
suite is the same thing the agent runs there, not a second suite. The scanner's
run is an independent confirmation that ticks the step; it does not replace the
agent's runs.

## Calls made while writing this (Claude's, for Rob to check)

- **"The PHP the site serves now"** is the version of the domain the site names as
  its server (`server_site_domain_id`), or its only domain. A test domain on X does
  not change it. If the sweep has no version for that domain, the check says "could
  not tell" (exit 2). If that version is already X or newer, the check lints on X
  alone.
- **Two versions reach a check** through environment variables set by `scan.sh`
  (the current PHP, and whether a test domain exists). The step's one `param` stays
  the target.
- **No new applicability flag.** A step a site does not need passes with a line
  saying why (for example "no test domain"). It shows as a tick, not an info mark,
  because the page's three marks belong to the site's standing, not to a line.
- **Order is by position, not enforced.** Steps in a track list in position order,
  and `/next-mg-step` takes the first open one, which is all the ordering this
  needs. Nothing blocks Rob from switching early; the order is advice.
- **The existing steps keep their identity.** `serves_php_8_5` and
  `rector_php_8_5_rules` stay where they are, so their `<step>.<shortcode>` issues,
  NAh marks and proven status survive. Only their how-to text changes, and the new
  steps are added in a new track. No migration.
- **Tier 1 check proves compilation only.** It lints every PHP file outside
  `vendor/` and generated output, templates included, on both runner images. The
  step makes the agent run Rector and PHPStan at the target; the check does not
  repeat them. Behaviour is Tier 2's job.
- **Old PHP.** The runner images that exist are 8.1, 8.3 and 8.5. A site serving
  something older gets "could not tell". Building older images is out of scope.

## Open for the builder

- The test-suite convention's file name and where each repo declares it.
- Nothing else on Tier 2's running; see the next section.
