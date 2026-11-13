# The scan follows a site's test domain

2026-11-13. Site names here are made up: `shop` is a site, `shop-test` its
test domain.

## Why

`/next-mg-step` does a site's next step, then hands back for Rob to deploy and
scan. The scanner reads a checkout only when it sits at the commit the site's
server runs. A site that upgrades on its test domain first, and deploys to
production only with someone's permission, therefore never moves on the board:
the scan skips it, and the skill keeps offering the step it already did.

`site-set --upgrade=<branch>` does not cover this. It is for a site with two
lines that really diverge, matched by branch name. On a site with one line and
a new branch per bubble, the name goes stale and every later commit reads as
a hotfix that was not ported.

## Goal

For a site with a test domain, deploying to the test domain is enough for the
scan to read the checkout. Rob never names a branch.

Not goals: changing any site's deploy script; marker branches; changing how a
site with an upgrade branch is scanned; scheduling the scanner.

Must not happen: a step ticks for work that runs nowhere. A deploy, to
production or to the test domain, stays Rob's approval.

## Approaches

- **A. The scanner asks the test domain what it runs (chosen).** No branch
  names, no deploy script changes, and it works for every site that has a
  test domain on mg.
- **B. Deploy scripts move fixed marker branches** (`staging`, `production`)
  and the scanner follows the marker. Same result with more parts: every
  deploy script changes and the scanner changes anyway. Rob does not want the
  markers for their own sake.
- **C. Scan any checkout that is ahead of production.** Least work, but a step
  would tick for work nobody has run.

Rob picked A.

## Design

### Scanner (`tools/site-standards/scan.py`)

`scan_site` decides, in this order, whether a clean checkout is read:

1. It is at the commit the server runs. As today.
2. The site has an upgrade branch on mg and the checkout is on it. As today.
   A site with an upgrade branch never reaches rule 3.
3. The site has a test domain on mg, the test domain answers over ssh with
   the checkout's commit, and the server's commit is an ancestor of it.
4. Otherwise it is skipped, as today.

The test domain is asked only when rules 1 and 2 did not apply, with the same
`ask_server` used for production, on `test.ssh_alias` and `test.directory`.

Rule 3 says, on stderr: `shop: checking what shop-test runs (abc1234); its
server runs def5678 (branch)`.

Skip reasons under rule 3, each naming both commits where it has them:

- the test domain could not be asked: the existing reason for the server's
  commit, then `; its test domain could not be asked: <why>`;
- the test domain runs something else: `its server runs X and its test domain
  runs Y; the checkout is at Z`;
- the server's commit is not in the checkout's history: `its server runs X,
  which the test domain's Y does not include`. This is the guard against a
  production fix that the test line lacks.

The site's entry in the document gains `test_commit`: the 40-character commit
the test domain answered with, or null when it was not asked or did not
answer. `SITE_TEST_COMMIT` is added to the check environment the same way.

### Importer (`ScanPayload`, `StandardsImporter`)

`ScanPayload` accepts an optional `test_commit`, a full 40-character hash or
null, like `server_commit`.

`importSite` allows a scan off the server's commit in two cases instead of
one: on the site's upgrade branch (as today), or when the site has a test
domain on mg, has no upgrade branch, and `commit` equals `test_commit`. Any
other off-server scan is refused, and the message says which of the two it
has on record.

`test_commit` is stored on `std_scan_sites` (new nullable column, migration
`db_schemas/55_standards_test_commit/`, applied by Rob).

### Board (`Board`, `templates/admin/dh/standards.tpl.php`)

Where the page now says "the checks read `<branch>` at `<commit>`, the line it
is preparing", a scan whose checked commit equals its stored `test_commit`
says instead "the checks read `<commit>`, which its test domain runs". The
marks are unchanged: checkmark, info circle, face.

### `/next-mg-step` skill (`~/.claude/skills/next-mg-step/SKILL.md`)

For a site with a test domain and no upgrade branch, the hand-back says to
deploy to the test domain and scan, and step 1b says the test domain is what
the scan will follow. The rule against setting `upgrade_branch` on a one-line
site stays.

### `CLAUDE.md`

The "Deployed means the server's commit" paragraph gains the test-domain
rule in two sentences.

## Calls made here, not by Rob

- A site with an upgrade branch ignores the test-domain rule, so the two-line
  site keeps behaving exactly as it does.
- `test_commit` is stored in a column rather than inferred on the page, so a
  later change to a site's facts cannot change what an old scan is said to
  have read.
- `js_hotfixes_ported` is left alone. The ancestor guard in rule 3 covers the
  same risk for a one-line site.
- `suite_passes_on_php` already asks the test domain for its commit; it keeps
  doing so itself rather than reading `SITE_TEST_COMMIT`.

## Tests

- Python (`tools/site-standards/tests/`): the four rule-3 outcomes with a fake
  ssh, and that a site with an upgrade branch never asks the test domain.
- Codeception Unit: `ScanPayload` takes and refuses `test_commit`; the
  importer accepts a test-domain scan, refuses one when the commits differ,
  when the site has no test domain, and when it has an upgrade branch.
