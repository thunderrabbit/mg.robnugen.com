# Names the steps make up

Some steps ask a site to have a file, or a line in a file, that no tool
outside these steps knows about. The checks are scripts with no judgment of
their own, so they can only find what has a fixed name. This page lists each
of those names, what reads it, and why it is spelled the way it is.

A step that invents a new name adds it here.

## Files at a site's repo root

### `JS_LIBRARIES.md`

The site's list of the JavaScript it loads. Written in step `js_inventory`
and kept up to date by every later JavaScript step.

Read by: `js_inventory` (every script must be named in it),
`js_checks_in_place` and `js_deprecations_clear` (the lines below).

### `testing-js-since-<yyyy-mmm-dd>.sh` or `.js`

The site's browser smoke test, dated the day it was added, with the month as
its lowercase three-letter abbreviation: `testing-js-since-2026-nov-08.sh`.
The date tells the next agent how long the site has had JavaScript tests,
without reading the history. A site with no JavaScript has no such file.

The `.sh` form is run as `./testing-js-since-<date>.sh` against the test
domain by standards-mcp's `run-webdriver.sh`, with `TEST_BASE_URL` set. It
fails on any error in the browser console, and given `--strict`, on warnings
too.

The `.js` form is for a site whose tests run under Node. The checks can see
it but not run it yet: standards-mcp's WebDriver runner has no Node in it.

A site that already has a browser suite does not have to wrap it in this
name. It names the suite's command in `JS_LIBRARIES.md` instead.

Read by: `js_checks_in_place` (it exists), `js_deprecations_clear` (runs the
`.sh` with `--strict`).

### `deploy_to_dh.sh`

The site's deploy: `git push`, then `git pull` over ssh in `DEST_PATH` on
`DEST`, with `PULL_URL` saying whether the server pulls over ssh (`origin`)
or https (empty). The text is base.robnugen.com's committed copy, which came
from new-DH-user-site; older sites' `deploy_on_dh.sh` and
`deploy_<site>_to_dh.sh` are renamed to it, so one name answers "how do we
deploy this?" on every site.

Read by: `deploy_script` (it is tracked and executable, and `DEST` and
`DEST_PATH` match the site's server).

### `safe-suite.sh`

The site's PHP suite that is safe to run against its test domain. Read by
`suite_passes_on_php`.

## Paths at a site's web root

### `/_php_version.php`

The site's answer to "which PHP serves you?": `PHP_VERSION` as plain text, to
a request from the server's own loopback address only, 404 to anyone else.
Not asked for by any step now (`web_php_version_readable` reads the server's package instead).

Read by: nothing on EC2 any more; the sweep reads the Apache PHP package on
the box instead, so a client site adds no probe file. mg keeps its own copy. The leading underscore keeps it out
of the way of a site's own routes; `php_version` alone would also be a name
`.gitignore`'s `php*` rule swallows in some sites.

## Trailers in commit messages

### `<Line>-has: <sha>`

On a commit on the line the server runs, for a site preparing an upgrade on
another branch: the commit on the upgrade branch that solves the same
problem a different way, so there is nothing to cherry-pick. The word before
`-has` is whatever the site calls its upgrade line (`Green-has: 0d225bc`).

Read by: `js_hotfixes_ported` (such a commit is not reported as unported,
while the named commit is on the upgrade branch).

## Lines in `JS_LIBRARIES.md`

Each is one line, starting at the beginning of the line. Where a line appears
more than once, the last one counts.

### `JS tests: <command>`

The command that runs the site's existing browser suite, for a site that does
not wrap it in `testing-js-since-<date>.sh`. It runs from the repo root inside
the WebDriver runner, with `TEST_BASE_URL` set to the test domain.

Read by: `js_checks_in_place` (it counts as the smoke test).

### `JS strict tests: <command>`

The same suite, run so that a warning in the browser console fails it too.
Step `js_deprecations_clear` needs this, not `JS tests:`, because a run that
passes on warnings can't show that they are gone.

Read by: `js_deprecations_clear` (runs it on the test domain). If both this
line and a `testing-js-since-<date>.sh` are present, this line is run.

### `CSS guard: <command>`

The command that refuses a commit quietly dropping a style rule, as the hook
runs it; its first word is a tracked file (`CSS guard: bin/css-commit-guard.php`).

Read by: `css_cascade_guarded` (the file is tracked and the commit-msg or
pre-commit hook names it).

### `Visual capture: <command>`

The command that captures every page of the test domain, screenshot and
computed styles, for the visual differ (`Visual capture: bin/visual-capture
--ref <branch>`).

Read by: `js_visual_baseline` (it is named; the check does not run it).

### `Visual ledger: <path>`

The tracked JSON file of visual differences a person has accepted, each entry
with `reason`, `who` and `date` (`Visual ledger:
Tests/Support/Data/AcceptedVisualDiffs.json`). A list, or an object whose one
list (under any key not starting with `_`) holds the entries.

Read by: `js_visual_baseline`.

### `Stays on <npm package> <major>: <why>`

A decision to hold one library on an older major release for now, with the
reason: `Stays on jquery 3: the people who use the site were promised one
careful upgrade; jQuery 4 is next year's project`. The package is named as npm
names it, in lowercase.

Read by: `js_libraries_current` (a library on exactly that major counts as
held, not behind; a library below it is still behind).

### `Shim: <path or url> -- <why>`

A compatibility shim the site wrote or kept itself, which no list of famous
shims would know: `Shim: wwwroot/js/forms.js -- $.browser polyfill for
jqTransform; goes with it`. A path is the tracked file's path; a url is as
the site loads it.

Read by: `js_shims_removed` (the step stays open while the file is tracked or
the script is loaded).

### `Deprecation warnings clear at <commit>`

For a site that has a smoke test but no test domain to run it on: the commit
at which the agent found no deprecation warnings. It holds while that commit
is in the site's history and the site loads the same libraries it loaded
then.

Read by: `js_deprecations_clear`, only when there is no test domain.
