---
title: Every library on a supported release
status: idea
whose_move: agent
check: js_libraries_current
---
Every third-party library is on a release its maintainers still support, or
has been replaced with one that is. Each library moves one major version at
a time, and each move is its own deploy. If a move breaks something, it can be
undone by reverting one commit, and the site is never more than one change away
from a version that was working.

The check asks the npm registry about each library the site loads from a CDN
(jsDelivr, unpkg, cdnjs, code.jquery.com, esm.sh, jspm), each copy in the repo
named `<npm package>-<x.y.z>.js` (loaded by a `<script src>` or named from
PHP), and each of `package.json`'s `dependencies`. A library passes when it is
on the newest major and its release is not marked deprecated. For a `0.x`
library, where every minor can break, it passes within ten minors of the
newest. The check lists everything it finds wanting, not just the first.

A site may decide to hold a library on an older major for a while: the people
who use it were promised a careful upgrade, or the next major is a separate
piece of work. It writes that decision in `JS_LIBRARIES.md` as one line,
`Stays on <npm package> <major>: <why>` (`Stays on jquery 3: the people who
use the site were promised one careful upgrade; jQuery 4 is next year's
project`), and the check counts that library as held, not behind, while it is
on that major. The line is the
site's own word, on record next to its reason; the check does not judge it.
The names are described in `tools/site-standards/NAMES.md`.

## How to do it

1. Work out the order from `JS_LIBRARIES.md`. A library goes after everything
   it depends on can take it: upgrade each plugin to a release that supports
   both the old and the new version of its base, and only then the base.
   Example: Bootstrap 4 needs jQuery, so get Bootstrap onto a release that
   runs on the jQuery you are moving to, or move to Bootstrap 5, which needs
   none.
2. If how a library is loaded must change too, change that first, at the same
   version, as its own deploy. Example: Three.js r148 dropped `examples/js/`
   and r160 dropped the `three.min.js` global build. A site on r145 using
   `THREE.OrbitControls` first moves to an import map with
   `three@0.145.0/build/three.module.js` and `examples/jsm/controls/OrbitControls.js`,
   still on r145, deploys that, and only then changes the version.
3. For each move of one library by one major:
   1. Read the library's upgrade guide for that major, all of it.
   2. Change the version (and its `integrity` hash), and fix what the guide
      says this site uses. Use the library's codemods where it has them
      (React and Vue do), and read every change they make.
   3. jQuery only: keep the jQuery Migrate for the new major loaded in
      production for this move, so a missed call still works and logs a warning
      instead of failing.
   4. Run ESLint and the smoke test on the test domain, read the console, and
      compare a visual capture from before the move with one after (the
      visual baseline step). Fix or accept every finding.
   5. Commit, update `JS_LIBRARIES.md`, and deploy. A copy kept in the repo
      is named after its npm package (`jquery-4.0.0.min.js`), so the check
      can look it up. Let it soak on production before the next move (the
      soak step says how long), and say so in the commit if you don't wait.
4. To hold a library on its current major on purpose, add the `Stays on` line
   with the reason, and take the line out when the hold ends.
5. A library nobody maintains (AngularJS, or a jQuery plugin with no release in
   years) gets replaced, as its own piece of work, never in the same commit as
   an upgrade: with a maintained library, or with what the browser now does
   itself (`$.ajax` → `fetch`, `$(sel)` → `document.querySelectorAll(sel)`).
   If the replacement is large, ask Rob before starting.
