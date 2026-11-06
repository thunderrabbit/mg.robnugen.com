# DreamHost dashboard: which sites are behind the standard

Date: 6 Nov 2026, revised 7 Nov 2026. Rob, with Claude (brainstormed in the mg
session).

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

**New ideas might come from anywhere.** Rob doing an upgrade on one site, Rob
wanting a new feature everywhere (the PHP version shown on each admin page), PHP
standards rising, a zero-day that needs fixing. Any site can be the first to have
an idea, and on a given day any site can be ahead of the others. The hope is that
each idea then reaches mg and the template itself, and from there the rest.

So **no site is the yardstick**. The standard is the set of written-up steps, and
every site, mg included, is measured against that set. mg is where Rob prefers to
try anything risky, because it has the widest safety net and nothing on it can be
harmed by a test record. A site that takes real orders must never be the place
where something needing test records is tried.

**The practice is meant to travel.** The template was itself forked from two
older codebases, ABB and ABF, which do not live on DreamHost and have not had
many of the upgrades the template has had since. Rob intends to use the same
upgrade practice on them eventually. They will not appear on `/admin/dh/`. They
could possibly appear on an `/admin/ec2/` dashboard one day, and that is
definitely a separate project. What it means for this one is in
[Beyond DreamHost](#beyond-dreamhost).

**Start small and look at it.** Rob finds it hard to say what he wants changed
without seeing the thing work. The first build covers two sites, mg and badmin,
and is expected to be revised once he has used it.

## Words used here

- **Site**: one codebase and the domains that serve it. Usually one domain is one
  site. A fork served on two domains is still one site. One domain can carry two
  sites at different paths (a Hugo site at `/` and Perl scripts under `/journal`).
- **Shortcode**: a site's short, unique handle, such as `mg` or `badmin`. It is
  what issue titles and commands use to name a site.
- **Standard**: the set of tracks for one kind of site. Only the **template**
  standard is built now. WordPress, MediaWiki, Hugo and Perl standards come later.
- **Track**: one line of improvement inside a standard. Tracks advance
  independently, in no fixed order.
- **Step**: one improvement on a track, with an explanation, a how-to, and a
  check. A step starts as an **idea** and becomes **proven** once one site has it.
- **Site note**: what was different about one step on one site, or why it could
  not be applied there. It is an mg issue in that site's own project.

## Goals

1. `/admin/dh/standards.php` lists each template site with one of two marks: a
   green checkmark (as up to date as it can be) or an info circle (behind).
2. Under each site is a list of its steps, collapsed by default, that expands to
   say what remains, why, and whose move it is (Rob's or an agent's).
3. A step is ticked by a **check that passes**, never by hand.
4. A step is a Markdown file in mg's repo, whichever site the idea came from. Rob
   approving a step is Rob advancing mg's master, which he already does.
5. Each step carries a how-to that gets better after every site it is applied to.
   Each site's notes on a step are an issue in that site's own mg project.
6. A domain's existing page shows the status of every site inside that domain.
7. The tables can later hold other standards, and sites that are not on
   DreamHost, without being redesigned.

## Non-goals

- Migrating any site. That work happens afterwards, one site at a time, each in its
  own bubble in that site's repo.
- Adding Rector to mg. That is the first piece of paired work after this is built.
- Entering all the template sites. The first build has mg and badmin; the others
  are added later, one command each.
- WordPress, MediaWiki, Hugo or Perl standards. Only their place in the tables.
- ABB, ABF, or an `/admin/ec2/` dashboard. No rows, no page, no checks for them.
  Only the room described in [Beyond DreamHost](#beyond-dreamhost).
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
- **Leaking the fleet.** mg's repo is public. Nothing about a particular site goes
  in it: no shortcodes, no site names, no per-site progress or notes. All of that
  lives in mg's database, behind Rob's login and each project's membership.

## The page

A new page in the existing DreamHost inventory, linked from its nav as
"standards", guarded the same way (`Inventory\AdminPage::guard`: Rob only).

```
Template sites   7 proven steps · trunks last checked 2026-11-07 01:20 UTC

ⓘ  mg          1 step to go                                           ▾
      Groundwork   ✓ ssh login works
                   ✓ repo located
                   ✓ checks in place (phpcs, PHPStan, unit tests)
      PHP version  ✓ serves PHP 8.5
      Tooling      ✓ tooling on PHP 8.5
      PHPStan      ✓ level 10, no baseline
      Rector       · Rector's 8.5 rules, one per commit      an agent can do this
                       Why and how: …
ⓘ  badmin      waiting on you                                         ▸

Ideas not yet tried on any site
      MLAPHP       remove globals from prepend.php
```

- One section per standard; only "Template sites" exists now. Sites are listed by
  shortcode. No site has a special row.
- A site appears on this page when at least one DreamHost domain is linked to it.
  A site hosted elsewhere never has such a link, so it never appears here.
- Row summary: `up to date`, `N steps to go`, or `waiting on you` when an open
  step is Rob's move.
- Expanding uses `<details>`, so no JavaScript. Open steps show their explanation;
  the how-to sits one level further in.
- A step with an issue in the site's project shows the issue's number, status and
  a link to it, whether the step is done or not.
- A step that does not apply to a site says so, links to the issue that gives the
  reason, and counts as done.
- A step that could not be checked says why ("no local checkout", "not checked
  yet") and shows as an open step with the info circle. It is not an error.
- Each site shows when its trunk was last checked. If the latest scan skipped it,
  the row says why ("on branch example-topic") beside that date.
- Below the sites, ideas are listed once, by track. They are not repeated under
  each site.

### On a domain's page

`/admin/dh/domain.php` gains a "Sites in this domain" panel: one line per site
linked to the domain, with its path when it has one, its mark, its summary, and a
link to its row on the standards page. Most domains show one line. A domain that
carries a Hugo site and Perl scripts will show two, once those standards exist.
A domain with no site entered yet shows nothing new.

### The rule for the mark

A step file says whether the step is an **idea** or **proven**.

- An **idea** has been written up but no site has done it yet. It counts against
  no one and is listed once, below the sites.
- A **proven** step has been done on at least one site and merged to that site's
  trunk. From then it counts for every site of the standard, mg included.

A site gets the green checkmark when every proven step is passed or marked as not
applying to it. Otherwise it gets the info circle.

Whoever proves a step flips it from idea to proven in the step file, in a commit
to mg's repo; it takes effect when Rob advances mg's master over it. A step that
is urgent (a zero-day fix) follows the same path, just quickly. The page stays
calm either way.

When a new PHP version arrives, its steps ("serve PHP 8.6", "Rector's 8.6 rules")
are written as ideas. Once one site proves them, every site shows the info circle
until it catches up.

## Tracks of the template standard

Rob's final state for a template site, in no particular order:

| Track | The top of the track |
|---|---|
| Rector | The newest PHP level set, plus the further rule sets such as type declarations |
| phpcs + phpcbf | PSR-12 with no exclusions left |
| PHPStan | Level 10, no baseline |
| MLAPHP | Every chapter of *Modernizing Legacy Applications in PHP* done |
| PHP version | The newest DreamHost offers (8.5 today) |

Further tracks are Claude's additions:

- **Groundwork**: what must be true before any other step can be worked. ssh login
  works (Rob's move), repo located on GitHub and checked out on Lemur 13 (Rob's
  move), checks in place.
- **Tooling**: the site's checks run on the same PHP the site is moving to (hook
  image, `composer.json` platform, standards-mcp `php_target`). The first
  migration found that Rector on an older image silently skips newer rules.
- **Security** and **Features**, for the other two sources Rob named: a fix every
  site needs, and a feature Rob wants everywhere. Empty in the first build.

A track is a directory, so adding one needs no migration. Order matters inside a
track (a step has a position). Across tracks it does not, except that Groundwork
comes first.

## Where a step lives

A step is a Markdown file in mg's repo:

    tools/site-standards/steps/<standard>/<track>/<step>.md

Frontmatter holds the title, the position, whether it is an idea or proven, whose
move it is, and the name of its check (with an optional parameter such as `8.5`).
The body has two parts: the explanation shown on the page, and the how-to an
agent follows.

**Only step files on mg's `master` count** *(Claude's call)*. The scanner reads
them from `master` with git, never from the working tree, so a step on an open
branch reaches no one until Rob has advanced master over it.

This is deliberate:

- A step is reviewed as a commit and approved when Rob advances master. When the
  idea was proven on mg, the step rides in the same bubble as the work. When it
  was proven on another site, it is a small commit of its own in mg's repo.
- The how-to's history is git history. Tightening it after a site is a commit.
- The check is code sitting beside the words that describe it.

mg's database holds a copy of the standards, tracks and steps so the page can
render them. The copy is replaced on each import; the files are the truth.

## Site notes

Rob's decision: a site's notes on a step are an **issue in that site's own mg
project**, not a file. mg's issue tracker is private, each site already has (or
gets) a project, and the notes sit beside the rest of that site's work.

- One issue per step per site. The same tweak on three sites is three issues.
  That repeats some text, and Rob prefers it: each is clear and explicit.
- The issue's title is the step's name, a dot, and the site's shortcode:
  `title_of_upgrade.abc-eli`.
- The shortcode in the title is a **check digit**. The issue must be in the
  project of the site that shortcode belongs to. An issue titled for one site but
  filed in another site's project is a mistake the page can point out.
- The issue is opened when work on that step starts on that site, or to record
  that the step cannot be applied there. A step with no issue simply has no notes.
- The description and comments hold how it went and what was different. Commits
  can be attached to the issue as they already can for any mg issue.

### Names

- A **step name** is lowercase letters, digits and underscores:
  `title_of_upgrade`. It is the step file's name without `.md`, and it is unique
  within a standard.
- A **shortcode** is lowercase letters and digits, in groups joined by single
  hyphens: `mg`, `badmin`, `abc-eli`. At most 24 characters. Unique across all
  sites, and never reused for a different site.
- An **issue title** for a site note is exactly `<step name>.<shortcode>`.

Underscores belong to step names and hyphens to shortcodes, so the one dot
always separates them *(Claude's call on the characters; Rob asked for a rule)*.

### What the page reads from the issue

The page finds a site's note by looking in the site's project for an issue with
that exact title. It shows the issue's number and status and links to it.

The step is still ticked only by its check. An issue marked Done beside a check
that has not passed is not an error; it usually means the work is on a branch
that has not reached trunk, and seeing both is useful.

**"Does not apply" needs a status** *(Claude's call)*. mg's issue statuses today
are Open, In Progress, Blocked and Done. This build adds a fifth, terminal one:
**Doesn't apply**. A site note in that status makes its step count as done for
that site, with the issue as the reason. It is the only way a person, not a
check, changes a site's mark, and it is useful for ordinary issues too.

Under each site the page also lists, quietly, any issue in its project whose
title has the site-note shape but names a different site or no known step. That
is the check digit doing its job, and it catches a renamed step.

Who can read or write a site's notes follows the project's membership, as for
any issue. An agent working on a site needs to be a member of that site's
project; Rob grants that.

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

1. The scanner asks mg for the site list (shortcode, standard, checkout path,
   trunk branch) over `ssh mg`.
2. It runs the checks, and reads the step files from `master` of the mg repo on
   Lemur 13 (with git, as above, whatever branch is checked out).
3. It streams one JSON document (standards, tracks, steps, plus per site: branch,
   commit, and each check's outcome and detail) over `ssh mg` to a
   PHP tool that reads STDIN and imports it in one transaction. A `--dry-run`
   rolls back, as the sweep importer does.

**Only a clean checkout on the site's trunk is checked** *(Claude's call)*. Each
site records its trunk branch (default `master`). If the checkout is on another
branch or has uncommitted changes, the scanner skips that site, records why, and
the site's last trunk results stand with their date. So a checkmark means "merged
to trunk", never "passes on a branch nobody has merged". It does not mean
"deployed": the scanner does not check what a server is running.

Site notes never pass through the scanner. They are already in mg.

The scanner is run by hand at first, for one site or all. Scheduling it beside
the daily sweep is a later, small change once it is trusted.

## Beyond DreamHost

Most of this design does not care where a site is hosted: standards, tracks,
steps, the how-to that improves, site notes as issues in a site's project, and
the scanner checks that run against a checkout on Lemur 13. Only three things are DreamHost-specific:

- the link from a site to its DreamHost domains,
- the checks mg answers from the DreamHost sweep (ssh login, PHP version served),
- the pages under `/admin/dh/`.

So the design keeps those three at the edge *(Claude's call)*:

- Host-neutral tables are named `std_*`. The one DreamHost link table is
  `dh_site_domains`. A later dashboard for another host adds its own link table
  and its own page, and reuses everything else.
- Step files and the scanner live under `tools/site-standards/`, not under a
  DreamHost name.
- A check that reads the DreamHost sweep is named as such, so a site hosted
  elsewhere is never asked a question only DreamHost can answer.

Left for that later project to decide: whether ABB and ABF join the template
standard or get a standard of their own that borrows its steps, and what stands
in for the two DreamHost-only checks. Nothing is built for them now.

## Tables (draft for Rob's 3NF review)

One migration, `45_site_standards`. Primary keys are unique across the project.
Nothing here references a sweep id, so the sweep rebuild rule still holds.

This table set has not had Rob's 3NF review yet. Reading this document is the
first pass. The build plan stops for a second pass on the exact `CREATE TABLE`
statements before the migration is committed or applied.

Copies of the files, replaced on each import:

| Table | Holds | Columns |
|---|---|---|
| `std_standards` | A kind of site | `std_standard_id`, `slug` (unique), `name` |
| `std_tracks` | A track of a standard | `std_track_id`, `std_standard_id` →, `slug`, `name`, `position`; unique (`std_standard_id`, `slug`) |
| `std_steps` | A step file | `std_step_id`, `std_track_id` →, `slug`, `position`, `title`, `explanation`, `how_to`, `status` (idea, proven), `whose_move` (rob, agent), `check_name`, `check_param` (nullable), `retired_on` (nullable); unique (`std_track_id`, `slug`) |

Kept by hand:

| Table | Holds | Columns |
|---|---|---|
| `std_sites` | A codebase | `std_site_id`, `std_standard_id` →, `shortcode` (unique), `project_id` (nullable → `projects`), `checkout_path` (nullable, relative to `~/work`), `trunk_branch` (default `master`), `github_repo` (nullable), `autonomy` (paired, reviewed, solo; default paired) |
| `dh_site_domains` | Which domains serve a site | `dh_site_domain_id`, `std_site_id` →, `dh_domain_id` → `dh_domains`, `path_prefix` (default empty); unique (`dh_domain_id`, `path_prefix`) |

Written by the scanner:

| Table | Holds | Columns |
|---|---|---|
| `std_scans` | One scanner run | `std_scan_id`, `scanned_at_utc`, `imported_at_utc`, `steps_commit` |
| `std_scan_sites` | What a scan saw of a site | `std_scan_site_id`, `std_scan_id` →, `std_site_id` →, `branch`, `commit_sha`, `skipped_reason` (nullable); unique (`std_scan_id`, `std_site_id`) |
| `std_step_states` | Outcome of a scanner check over time | `std_step_state_id`, `std_site_id` →, `std_step_id` →, `from_scan_id` →, `until_scan_id` (nullable →; NULL = current), `outcome` (pass, fail, unknown), `detail` |

One row added to an existing table: `issue_statuses` gains the terminal status
"Doesn't apply".

Notes on the draft:

- "Replaced on each import" keeps ids stable: a row is matched by its slug and
  updated in place, so `std_step_states` keeps pointing at the same step. A step
  removed from the files is retired (`retired_on`), not deleted, so its history
  stays.
- `std_step_states` uses the same span pattern as the sweep tables (`from` /
  exclusive `until`, NULL = current), so history is kept without a row per scan.
  `Inventory\SpanPlan` already holds that rule.
- A site has exactly one standard. A domain with two kinds of code is two sites,
  told apart by `path_prefix`.
- `std_sites` holds nothing about hosting. Where a site is served is said only by
  a link table (`dh_site_domains` today), so a site on another host needs no
  change to it.
- A step's name must be unique within its standard, not just within its track,
  because an issue title names the step without its track. The importer refuses
  two steps with the same name in one standard.
- **No table links a site note to its step.** The link is the issue's title and
  the site's `project_id`, read when the page loads. Storing an issue id per site
  and step would be a second copy of what the title already says.
- A project can be the home of more than one site (a codebase served as two
  sites). The shortcode in the title still tells their notes apart.
- `std_sites.autonomy` is Rob's instruction for the site from now on. How a
  particular step was done (paired, reviewed, solo) is said in its issue, not in
  a column.
- The undeployed template skeleton has no domain, so it would not appear on the
  page. Rob hopes each idea reaches the template itself, so it will need a way
  onto the page when it is entered. Left open until then.

## Writing the hand-kept facts

The pages are read-only in this build. The only facts kept by hand are about
sites, written with a command-line tool over `ssh mg`, by Rob or by the agent
working beside him:

- add a site with its shortcode, standard and mg project
- link its domains, with a path when the domain carries more than one site
- record its checkout path, trunk branch and GitHub repo
- set its autonomy

Steps are written by editing files. Site notes are written as issues, with the
tools that already write issues.

## What ships in the first build

- The migration, the importer, the scanner, the command-line tool, the standards
  page, and the new panel on the domain page.
- The template standard, its tracks, and proven step files only for what is
  already done somewhere: the Groundwork steps, tooling on PHP 8.5, PHPStan level
  10 with no baseline, serves PHP 8.5, and the Rector 8.5 and type-declaration
  steps already proven on another fork. The how-to for those last two is copied
  from that fork's notes and tightened when mg does them.
- The "Doesn't apply" issue status.
- Two site rows: `mg` and `badmin` (one site, two domains), each tied to its mg
  project. Both will show the
  info circle from day one, which is the point of having something to look at.
- No phpcs-without-exclusions or MLAPHP steps as proven. Where an idea is already
  in mind it may be written up as an idea.

## Left open on purpose

- **A smoke test per site.** Rob would wince if a site went down, so no step that
  deploys should run on a site without one. What a smoke test is, and its
  Groundwork step, get designed during the first paired work.
- **How much an agent may do alone.** Rob expects this to become clear over the
  first few sites. Until then `autonomy` defaults to paired, and nothing reads it
  but people and agents deciding how to work.
- **How to detect a finished MLAPHP chapter.** Designed chapter by chapter, when
  the first site does each one.
- **How the template skeleton gets onto the page.**

## Left to the builder

- Class names and layout under `classes/Inventory/`, and the exact shape of the
  JSON document and the command-line tool.
- The frontmatter keys of a step file.
- Where "Doesn't apply" sorts among the statuses, and checking that boards and
  counts which list statuses by name still read well with a fifth one.
- How a track's display name and position are written down beside its directory.
- Column types, indexes and the order of files inside the migration.
- The pure rule for a site's mark and summary gets Unit tests with made-up sites;
  so do the step-file parser and the title rule for site notes.
- The importer gets no committed database test, matching the sweep importer, whose
  database runs were done by hand and never committed. Verify it by hand: a run
  against a throwaway `mysql:8.0` container on Lemur 13, then `--dry-run` against
  production before the first real import. No new test suite.
- Icons are plain characters or inline SVG in the existing page style; no icon
  library.
