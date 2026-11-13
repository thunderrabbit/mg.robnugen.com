---
title: A commit cannot quietly drop a style rule
status: idea
whose_move: agent
check: css_cascade_guarded
---
A hook refuses any commit that removes or weakens a rule in the site's
stylesheets unless the commit says so. A library upgrade brings a new vendor
stylesheet, and a selector in it can outrank one of the site's own without a
single line of the site's CSS changing; half the regressions one site found
after a jQuery upgrade were of this kind, and every page had passed its
console sweep. A guard that diffs the resolved cascade at commit time catches
the half that lives in stylesheets. The browser-side half is the visual
baseline step.

The site names its guard in `JS_LIBRARIES.md` as `CSS guard: <command>`, the
command's first word being a tracked file. The check passes when that file
exists and the `commit-msg` or `pre-commit` hook in the tracked hooks
directory runs it. A site with no stylesheets of its own passes. The names
are described in `tools/site-standards/NAMES.md`.

## How to do it

1. Write a command that reads the page's stylesheets in order, resolves them
   into one cascade (which declaration wins per selector), and compares the
   staged bundle with `HEAD`'s. Every rule lost, or changed in a way that
   reduces styling, is a finding.
2. Let a finding through only when acknowledged: a trailer in the commit
   message (`CSS-Change-OK: <selector> -- <why>`) for a one-off, or an entry
   in a tracked ledger of accepted differences, with who accepted it, when
   and why, for one that would keep coming back.
3. Call it from the tracked `commit-msg` hook, so an unacknowledged loss
   blocks the commit, and name it in `JS_LIBRARIES.md`.
