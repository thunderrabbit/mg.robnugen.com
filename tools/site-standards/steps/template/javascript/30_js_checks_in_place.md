---
title: Checks in place for JavaScript (smoke test, ESLint)
status: idea
whose_move: agent
check: js_checks_in_place
---
The repo has a browser smoke test and ESLint, and its pre-commit hook runs
ESLint. PHP's checks never load a page in a browser, so they cannot see a
script that stopped working. Every later step in this track changes what the
browser runs, and these checks are what say a change did not break anything.

The smoke test is either a `testing-js-since-<yyyy-mmm-dd>.sh` (or `.js`) at
the repo root, dated the day it was added, or, for a site that already has a
browser suite, a `JS tests: <command>` line in `JS_LIBRARIES.md` naming it.
Wrapping an existing suite in the dated name is allowed, never required. The
test opens each page that runs scripts, uses its main feature once, and fails
on any error in the browser console. ESLint's config is `eslint.config.js`
(or `.mjs`/`.cjs`) at the repo root. The check looks for the smoke test,
ESLint and the hook, in the tracked directory `core.hooksPath` names
(`.githooks/` by convention; a site with its hooks in `hooks/` is fine). A
site that loads no scripts and has no inline
`<script>` passes without them. Every name used here is described in
`tools/site-standards/NAMES.md`.

## How to do it

1. If the site already has a browser suite, keep it. Add `JS tests: <command>`
   to `JS_LIBRARIES.md`, with the command that runs it from the repo root,
   and `JS strict tests: <command>` with the one that also fails on console
   warnings (the deprecation step runs that one). Make sure it fails on errors
   in the browser console; if it doesn't, add that.
   Otherwise, write the smoke test, against the code as it is today, as
   `testing-js-since-<today, e.g. 2026-nov-08>.sh` at the repo root. For each
   page that runs scripts: open it, do the one thing that page is for (for a
   3D viewer: paste coordinates, see the canvas draw them), and assert that
   the console has no errors. In Chrome the console is
   `$I->executeInSelenium(fn ($wd) => $wd->manage()->getLog('browser'))`. Give
   it `--strict` to fail on console warnings as well.
   Either way, get it passing on the test domain:
   `TEST_BASE_URL=https://<test domain> run-webdriver.sh <repo> <command>`.
2. ESLint reads `.js` files, not templates. If the site's own code is inline in
   `.php` templates, move each block into a `.js` file the template loads, one
   template per commit, values from PHP passed through a small inline
   `window.SITE_CONFIG = {...}` block or `data-` attributes. Deploy each move
   to the test domain and run the smoke test. Change nothing else in these
   commits.
3. Add `eslint.config.mjs` with only the rules that find real faults:
   `no-undef`, `no-unused-vars`, `no-redeclare`, `no-dupe-keys`. Declare the
   globals the site really has: `browser`, and each library's global (`THREE`,
   `jQuery`, `$`). Style rules are not this step.
4. Run ESLint in a pinned Node image (Lemur 13 has no Node), like the hook
   runs PHPStan: `docker run --rm -v "$PWD":/app -w /app node:<exact version>
   npx --yes eslint@<exact version> .` Fix what it finds, or leave a commented
   `// eslint-disable-next-line <rule> -- <why>` where the code is right.
5. Add that ESLint run to the repo's tracked `pre-commit` hook (the directory
   `core.hooksPath` names, `.githooks/` by convention), so a commit with a
   JavaScript fault is refused.
6. Commit and deploy.
