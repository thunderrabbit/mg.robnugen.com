---
title: Checks in place for JavaScript (smoke test, ESLint)
position: 30
status: idea
whose_move: agent
check: js_checks_in_place
---
The repo has a browser smoke test and ESLint, and its pre-commit hook runs
ESLint. PHP's checks never load a page in a browser, so they cannot see a
script that stopped working. Every later step in this track changes what the
browser runs, and these checks are what say a change did not break anything.

The smoke test is a WebDriver suite under `Tests/Webdriver/`, run with
standards-mcp's `run-webdriver.sh` against the test domain. It opens each page
that runs scripts, uses its main feature once, and fails on any error in the
browser console. ESLint's config is `eslint.config.js` (or `.mjs`/`.cjs`) at
the repo root. The check looks for both, and for the hook. A site that loads
no scripts and has no inline `<script>` passes without them.

## How to do it

1. Write the smoke test first, against the code as it is today, and get it
   passing on the test domain. For each page that runs scripts: open it, do the
   one thing that page is for (for a 3D viewer: paste coordinates, see the
   canvas draw them), and assert that the console has no errors. In Chrome the
   console is `$I->executeInSelenium(fn ($wd) => $wd->manage()->getLog('browser'))`.
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
5. Add that ESLint run to `.githooks/pre-commit`, so a commit with a JavaScript
   fault is refused.
6. Add the smoke test command to `JS_LIBRARIES.md`, so the agent doing the
   next step can find it. Commit and deploy.
