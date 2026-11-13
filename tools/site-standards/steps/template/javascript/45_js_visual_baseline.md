---
title: A visual differ can show what a move changed
status: idea
whose_move: agent
check: js_visual_baseline
---
Before and after each library move, the site's pages are captured on the test
domain, a full-page screenshot and the computed style of the elements that
matter on each, and the two captures are compared. A console sweep proves a
page loaded; it says nothing about a stretched field, a sorted column gone
grey, a marker missing from a map, or a lightbox under its own backdrop. Each
of those passed a sweep once and reached a site's users.

A differ ranks; it never passes or fails. Someone reads the report, and each
difference is either fixed or written into a tracked ledger with who accepted
it, when and why. So the check can only see that the instrument is in place:
`JS_LIBRARIES.md` names the capture in a `Visual capture: <command>` line and
the ledger in a `Visual ledger: <path>` line, and the ledger is a tracked
JSON file whose every entry has `reason`, `who` and `date`. A site that runs
no JavaScript passes. The names are described in
`tools/site-standards/NAMES.md`.

## How to do it

1. Write the capture against the test domain: log in, visit every page the
   smoke test's list names, and write a screenshot and a computed-style
   fingerprint per page into a directory named after the build the server
   says it served. Playwright from this machine does it; no browser grid is
   needed. Give it a second mode that drives the clicks a page load never
   performs (a sort on every table, the dialogs behind the bugs you have
   already had), and times them.
2. Write the differ: changed pixel regions, changed style properties, moved
   elements, elements on one side only, slower steps. Rank pages by how much
   changed. Write a report one person can read in ten minutes.
3. Add the ledger, and have the report fold accepted findings away. Without
   it the report is wallpaper by the third run.
4. For each move in the libraries step: capture before, deploy, capture
   after, read the report, fix or accept everything, then let the move soak.
5. Name the capture command and the ledger in `JS_LIBRARIES.md`.
