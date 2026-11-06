# DreamHost dashboard: which sites are behind mg

Date: 6 Nov 2026. Rob, with Claude (brainstormed in the mg session).

## Intent

Rob runs about 20 sites forked from the same template (`new-DH-user-site`), plus
WordPress, MediaWiki and static sites. One fork was recently brought up to PHP 8.5
standards by hand: Rector's 8.5 rules, type declarations, tooling moved to the 8.5
image. Nothing records which of the other sites have had the same care, and the
standard keeps moving (more Rector, PHPStan, the chapters of *Modernizing Legacy
Applications in PHP*, PHP 8.6 next).

Rob's words for what this is: "learning how to work well with LLMs in migrating a
fleet of sites up to modern PHP standards, each one getting its own careful
handling." The page built here is the memory for that: a calm list of which
template sites are behind, what each one still needs, and whose move it is.

**mg is the gold standard.** Every improvement is proven on mg first, because mg
has the widest safety net and nothing on it can be harmed by a test record. A
client site that takes real orders must never be the lab. The other sites only
ever receive what mg has already proven.

## Words used here

- **Site**: one codebase and the domains that serve it. A fork served on three
  domains is one site. One domain can carry two sites at different paths (a Hugo
  site at `/` and Perl scripts under `/journal`).
- **Standard**: the set of tracks for one kind of site. Only the **template**
  standard is built now. WordPress, MediaWiki, Hugo and Perl standards come later.
- **Track**: one line of improvement inside a standard. Tracks advance
  independently, in no fixed order.
- **Step**: one proven improvement on a track, with an explanation, a how-to, and a
  check.
- **Reference site**: the site a standard is measured against. For the template
  standard it is mg.

## Goals

1. `/admin/dh/standards.php` lists every template site with one of two marks: a
   green checkmark (as up to date as it can be) or an info circle (behind mg).
2. Under each site is a list of its steps, collapsed by default, that expands to
   say what remains, why, and whose move it is (Rob's or an agent's).
3. A step is ticked by a **check that passes**, never by hand.
4. A new step is born in the same mg bubble that proves it, as a file in mg's repo.
   Rob approving the step is Rob advancing master, which he already does.
5. Each step carries a how-to that gets better after every site it is applied to,
   and each site records how much hand-holding each step took.
6. The tables can hold other standards later without being redesigned.

## Non-goals

- Migrating any site. That work happens afterwards, one site at a time, each in its
  own bubble in that site's repo.
- Adding Rector to mg. That is the first piece of paired work after this is built,
  and it will add the first Rector step files.
- WordPress, MediaWiki, Hugo or Perl standards. Only their place in the tables.
- Changing anything in the DreamHost panel. Switching a site's PHP version stays
  Rob's manual step.
- Starting agents, deploying, or notifying anyone. The page is read, not obeyed.
- Site-down monitoring.

## Anti-goals

- **Urgency.** Every site here is working. This feature never shows a warning
  triangle, red text, or a count styled as a problem. The warning triangle is
  reserved for a real site-down emergency, which this feature does not detect.
- **A checkmark that lies.** No hand-ticked boxes, and no checkmark left standing
  on a result nobody can date.
- **The scanner changing a repo.** It reads; it never writes, commits, or deploys.
- **New credentials.** Nothing here adds an API key, a route for agents, or a
  secret on Lemur 13.
- **Leaking the fleet.** mg's repo is public. Real site names, DreamHost usernames
  and per-site progress live only in the database behind Rob's login. Step files,
  tests and comments use mg or made-up names.

## The page

A new page in the existing DreamHost inventory, linked from its nav as
"standards", guarded the same way (`Inventory\AdminPage::guard`: Rob only).

```
Template sites   measured against mg · last checked 2026-11-06 01:20 UTC

✓  mg                  the reference                                  ▸
ⓘ  example-games       3 steps to go                                  ▾
      Groundwork       ✓ ssh login works
                       ✓ repo located
                       ✓ checks in place (phpcs, PHPStan, unit tests)
      PHP version      ✓ serves PHP 8.5
      Tooling          · point the tooling at PHP 8.5        an agent can do this
                           Why and how: …
      PHPStan          ✓ level 10, no baseline
      Rector           · Rector's 8.5 rules, one per commit  an agent can do this
                       · declare the types Rector can prove  an agent can do this
ⓘ  example-dreams      waiting on you                                 ▸
```

- One section per standard; only "Template sites" exists now. The reference site
  is the first row.
- Row summary: `the reference`, `up to date`, `N steps to go`, or `waiting on you`
  when an open step is Rob's move.
- Expanding uses `<details>`, so no JavaScript. Open steps show their explanation;
  the how-to sits one level further in.
- A done step shows how it was done (paired, reviewed or solo) and any note about
  what was different on that site.
- A step that does not apply to a site shows its reason and counts as done.
- A step that could not be checked says why ("no local checkout", "not checked
  yet") and shows as an open step with the info circle. It is not an error.
- Each site shows when it was last checked and which branch the check saw.

### The rule for the mark

A step file says whether the step is **proven** or only **planned**.

- A **proven** step has been done on mg. It counts for every site of the standard,
  mg included. A site gets the green checkmark when every proven step is passed or
  marked as not applying. Otherwise it gets the info circle.
- A **planned** step is where mg is heading next. It shows only on the reference
  site's own row ("2 planned") and counts against no one.

This is what makes "behind" mean "behind mg" and not "behind the final state". It
is also deliberately not computed from mg's latest check result *(Claude's call)*:
if mg's own check fails or cannot run one day, no other site's mark changes; mg's
row simply shows the info circle like anyone else's.

*Claude's guess, not yet confirmed by Rob:* when a new PHP version arrives, its
steps ("serve PHP 8.6", "Rector's 8.6 rules") are added as planned. The mg bubble
that does the work flips them to proven, and from then every other site shows the
info circle until it catches up.

## Tracks of the template standard

Rob's final state for a template site, in no particular order:

| Track | The top of the track |
|---|---|
| Rector | The newest PHP level set, plus the further rule sets such as type declarations |
| phpcs + phpcbf | PSR-12 with no exclusions left |
| PHPStan | Level 10, no baseline |
| MLAPHP | Every chapter of *Modernizing Legacy Applications in PHP* done |
| PHP version | The newest DreamHost offers (8.5 today) |

Two more tracks are Claude's additions:

- **Groundwork**: what must be true before any other step can be worked. ssh login
  works (Rob's move), repo located on GitHub and checked out on Lemur 13 (Rob's
  move), checks in place.
- **Tooling**: the site's checks run on the same PHP the site is moving to (hook
  image, `composer.json` platform, standards-mcp `php_target`). The first
  migration found that Rector on an older image silently skips newer rules.

Order matters inside a track (a step has a position). Across tracks it does not,
except that Groundwork comes first.

## Where a step lives

A step is a Markdown file in mg's repo, one per step, under
`tools/dh-standards/steps/<standard>/<track>/`. Frontmatter holds the title, the
position, whether it is planned or proven, whose move it is, and the name of its
check (with an optional parameter such as `8.5`). The body has two parts: the
explanation shown on the page, and the how-to an agent follows.

**Only step files on mg's `master` count** *(Claude's call)*. The scanner reads
them from `master` with git, never from the working tree, so a step in an open
bubble reaches no one until Rob has advanced master over it.

This is deliberate:

- The step is added in the bubble that proves it on mg, so it is reviewed with the
  work and approved when Rob advances master.
- The how-to's history is git history. Tightening it after a site is a commit.
- The check is code sitting beside the words that describe it.

mg's database holds a copy of the steps so the page can render them. The copy is
replaced on each import; the files are the truth.

## Two kinds of check

**Checks mg can answer itself**, computed when the page loads, from tables it
already has. Nothing is stored for these:

- *ssh login works*: every DreamHost user serving the site's domains has
  `dh_users.login_confirmed_on` set, which the existing `recover-dh-user` skill
  writes when a login is proven. An alias with no proven login does not pass; the
  step's detail says "alias found, login not proven yet".
- *repo located*: the site has both a GitHub repo and a Lemur 13 checkout path
  recorded.
- *serves PHP X*: every domain of the site has `php_version` ≥ X in the current
  sweep.

**Checks only Lemur 13 can answer**, because the checkouts and Docker live there.
A scanner on Lemur 13 runs one small script per check against each site's
checkout and pushes the outcomes to mg. Each script takes a repo path, exits
0 (pass), 1 (not yet) or 2 (could not tell), and prints one line of detail. It
runs the site's code only inside the standards-mcp runner (read-only mount, no
network), the same way the gates do. Examples for the first build:

- *checks in place*: `phpcs.xml`, `phpstan.neon` and a Unit suite exist, and the
  pre-commit hook is enabled.
- *tooling on PHP X*: hook image, `composer.json` platform and `php_target` agree.
- *PHPStan level 10, no baseline*: read from `phpstan.neon`, and the run is clean.

## How results reach mg

The same way sweeps do, and for the same reason: Lemur 13 starts everything, mg
never reaches Lemur 13, and no new credential is needed.

1. The scanner asks mg for the site list (site, standard, checkout path) over
   `ssh mg`.
2. It runs the checks, and reads the step files from its own mg checkout.
3. It streams one JSON document (steps, plus per site: branch, commit, and each
   check's outcome and detail) over `ssh mg` to a PHP tool that reads STDIN and
   imports it in one transaction. A `--dry-run` rolls back, as the sweep importer
   does.

**Only a clean checkout on the site's trunk is checked** *(Claude's call)*. Each
site records its trunk branch (default `master`). If the checkout is on another
branch or has uncommitted changes, the scanner skips that site, records why, and
the site's last trunk results stand with their date. So a checkmark means "merged
to trunk", never "passes on a branch nobody has merged". It does not mean
"deployed": the scanner does not check what a server is running. mg is no
exception, so mg is scanned after a bubble closes and Rob has advanced master.

At first the scanner is run by hand, for one site or all (after a bubble closes).
Scheduling it beside the daily sweep is a later, small change once it is trusted.

## Tables (draft for Rob's 3NF review)

One migration, `45_dh_standards`. Primary keys are unique across the project.
Nothing here references a sweep id, so the sweep rebuild rule still holds.

This table set has not had Rob's 3NF review yet. Reading this document is the
first pass. The build plan stops for a second pass on the exact `CREATE TABLE`
statements before the migration is committed or applied.

| Table | Holds | Columns |
|---|---|---|
| `dh_standards` | A kind of site | `dh_standard_id`, `slug` (unique), `name`, `reference_site_id` (nullable → `dh_sites`) |
| `dh_tracks` | A track of a standard | `dh_track_id`, `dh_standard_id` →, `slug`, `name`, `position`; unique (`dh_standard_id`, `slug`) |
| `dh_steps` | Copy of a step file | `dh_step_id`, `dh_track_id` →, `slug`, `position`, `title`, `explanation`, `how_to`, `status` (planned, proven), `whose_move` (rob, agent), `check_name`, `check_param` (nullable), `retired_on` (nullable); unique (`dh_track_id`, `slug`) |
| `dh_sites` | A codebase | `dh_site_id`, `dh_standard_id` →, `name` (unique), `checkout_path` (nullable, relative to `~/work`), `trunk_branch` (default `master`), `github_repo` (nullable), `autonomy` (paired, reviewed, solo; default paired) |
| `dh_site_domains` | Which domains serve a site | `dh_site_domain_id`, `dh_site_id` →, `dh_domain_id` → `dh_domains`, `path_prefix` (default empty); unique (`dh_domain_id`, `path_prefix`) |
| `dh_scans` | One scanner run | `dh_scan_id`, `scanned_at_utc`, `imported_at_utc`, `steps_commit` |
| `dh_scan_sites` | What a scan saw of a site | `dh_scan_site_id`, `dh_scan_id` →, `dh_site_id` →, `branch`, `commit_sha`, `skipped_reason` (nullable); unique (`dh_scan_id`, `dh_site_id`) |
| `dh_step_states` | Outcome of a scanner check over time | `dh_step_state_id`, `dh_site_id` →, `dh_step_id` →, `from_scan_id` →, `until_scan_id` (nullable →; NULL = current), `outcome` (pass, fail, unknown), `detail` |
| `dh_site_steps` | What people recorded about a step on a site | `dh_site_step_id`, `dh_site_id` →, `dh_step_id` →, `not_applicable_reason` (nullable), `done_mode` (nullable: paired, reviewed, solo), `note` (nullable), `issue_id` (nullable); unique (`dh_site_id`, `dh_step_id`) |

Notes on the draft:

- `dh_step_states` uses the same span pattern as the sweep tables (`from` /
  exclusive `until`, NULL = current), so history is kept without a row per scan.
  `Inventory\SpanPlan` already holds that rule.
- A site has exactly one standard. A domain with two kinds of code is two sites.
- `dh_sites.autonomy` is Rob's instruction for the site from now on;
  `dh_site_steps.done_mode` is the record of what happened on one step.
- A step removed from the files is retired (`retired_on`), not deleted, so its
  history stays.
- `dh_standards.reference_site_id` and `dh_sites.dh_standard_id` point at each
  other; the first is nullable and filled after the site row exists.

## Writing the hand-kept facts

The page is read-only in this build. The few facts people keep are written with a
command-line tool over `ssh mg`, by Rob or by the agent working beside him:

- add a site, link its domains, record its checkout path and GitHub repo
- set a site's autonomy
- record how a step was done on a site, with a note and an mg issue number
- mark a step as not applying to a site, with a reason

## What ships in the first build

- The migration, the importer, the scanner, the command-line tool and the page.
- The template standard, its tracks, and step files only for what mg passes today:
  the Groundwork steps, tooling on PHP 8.5, PHPStan level 10 with no baseline, and
  serves PHP 8.5.
- Site rows for the template forks, entered with the tool. Sites with no checkout
  yet are entered with what is known; their open Groundwork steps say the rest.
- No Rector, phpcs-without-exclusions or MLAPHP steps. mg does not pass them yet;
  each arrives with the mg bubble that proves it.

## Left open on purpose

- **A smoke test per site.** Rob would wince if a site went down, so no step that
  deploys should run on a site without one. What a smoke test is, and its
  Groundwork step, get designed on mg during the first paired work.
- **How much an agent may do alone.** Rob expects this to become clear over the
  first few sites. Until then `autonomy` defaults to paired, and nothing reads it
  but people and agents deciding how to work.
- **How to detect a finished MLAPHP chapter.** Designed chapter by chapter, when
  mg does each one.

## Left to the builder

- Class names and layout under `classes/Inventory/`, and the exact shape of the
  JSON document and the command-line tool.
- The frontmatter keys of a step file.
- Column types, indexes and the order of files inside the migration.
- The pure rule for a site's mark and summary gets Unit tests with made-up sites;
  so does the step-file parser.
- The importer gets no committed database test, matching the sweep importer, whose
  database runs were done by hand and never committed. Verify it by hand: a run
  against a throwaway `mysql:8.0` container on Lemur 13, then `--dry-run` against
  production before the first real import. No new test suite.
- Icons are plain characters or inline SVG in the existing page style; no icon
  library.
