---
title: Every library on a supported release
position: 50
status: idea
whose_move: agent
check: js_libraries_current
---
Every third-party library is on a release its maintainers still support, or
has been replaced with one that is. Each library moves one major version at
a time, and each move is its own deploy. If a move breaks something, it can be
undone by reverting one commit, and the site is never more than one change away
from a version that was working.

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
   4. Run ESLint and the smoke test on the test domain, and read the console.
   5. Commit, update `JS_LIBRARIES.md`, and deploy. Let it run for a day before
      the next move, and say so in the commit if you don't wait.
4. A library nobody maintains (AngularJS, or a jQuery plugin with no release in
   years) gets replaced, as its own piece of work, never in the same commit as
   an upgrade: with a maintained library, or with what the browser now does
   itself (`$.ajax` → `fetch`, `$(sel)` → `document.querySelectorAll(sel)`).
   If the replacement is large, ask Rob before starting.
