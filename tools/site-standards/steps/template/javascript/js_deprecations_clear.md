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

## How to do it

1. For each library in `JS_LIBRARIES.md`, find how it reports deprecations,
   and switch that on in the test domain only:
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
4. When no warnings are left, remove the warning switch from the test domain
   (Migrate stays out of production until the upgrade step loads it on purpose).
   Write in `JS_LIBRARIES.md`, for each library, that its warnings are clear,
   with the date and commit.
