---
title: No deprecation warnings on today's versions
position: 40
status: idea
whose_move: agent
check: js_deprecations_clear
---
With every library still at the version the site runs today, its deprecation
warnings are switched on and none of them fire. A library's next major release
removes what its last one deprecated. Code that stops using those things
first runs the same on the old version and the new one, so the upgrade itself
becomes a version change and nothing more.

Nothing is upgraded in this step. Each fix is deployed on the versions the
site already has.

A site that loads no third-party libraries passes at once. Otherwise the
step needs the smoke test from the checks step. With a test domain running the
checkout's commit, the check runs the strict form of it there, so any console
warning keeps the step open: the `JS strict tests: <command>` line in
`JS_LIBRARIES.md` if there is one, or else `testing-js-since-<date>.sh
--strict`. A `.js` smoke test can't be run by the checks yet, so the step
waits for it. A site with a smoke test but no test domain records a line
`Deprecation warnings clear at <commit>` in `JS_LIBRARIES.md`, which passes
while that commit is in the checkout's history and the site loads the same
libraries now as it did then. The names are described in
`tools/site-standards/NAMES.md`.

## How to do it

1. For each library in `JS_LIBRARIES.md`, find how it reports deprecations,
   and switch that on while you work, locally or on the test domain:
   - jQuery: load the jQuery Migrate release for the major you will move to,
     right after jQuery. Its non-minified build logs one console warning
     for each removed or changed API the site calls.
   - React: move to the last minor of the current major (18.3 before 19) and use
     the development build; that minor exists to warn about the next major.
   - Vue 2: use the last 2.7 release; for the move to 3, `@vue/compat`.
   - Others: read the library's upgrade guide for the next major. Most list
     what they removed. Three.js prints to the console when a deprecated name
     is used.
2. Run the smoke test and go through the site by hand, reading the console.
   Write each warning down in `JS_LIBRARIES.md` under its library.
3. Fix one kind of warning per commit, in a way that works on the old version
   too (`$.trim(x)` → `x.trim()`, `.bind()` → `.on()`). Deploy to the test
   domain, run the smoke test, then deploy.
4. When no warnings are left, and only if the site has no test domain, add the
   line `Deprecation warnings clear at <commit>` to `JS_LIBRARIES.md`, naming
   the commit you checked. Then take
   out whatever you switched on only for this step: jQuery Migrate stays out
   of production until the upgrade step loads it on purpose.
