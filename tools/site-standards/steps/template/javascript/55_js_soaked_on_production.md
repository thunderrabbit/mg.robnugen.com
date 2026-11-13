---
title: Production has run the last library change for five working days
status: idea
whose_move: agent
check: js_soaked_on_production
param: 5
---
The last library change production runs has run there for five working
days, and the people who use the site were told what changed. A move is not done when the
test domain is clean: the people the site is for may never visit the test
domain, and the bugs that matter are in what they do all day, which no
scenario covers in full. Five working days is one week of their work with
the new code. Only then does the next move start, so a problem can always be
read against one change.

A library change is a commit that touched `JS_LIBRARIES.md`, which the
inventory step keeps naming every library's exact version. The check reads the
server's reflog for the first deploy that carried that commit and counts the
working days since. A deploy that changes no library does not restart the
count, so fixing a typo the day after a move costs nothing. A site that loads
no third-party libraries passes. A server deployed by copying files, with no
git history to ask, is reported as such.

## How to do it

1. After each move in the libraries step: deploy, tell the people who use the
   site in one sentence what changed and what to look out for, and wait.
2. Watch the error log and listen. A report in the first days is read against
   this one move, which is the point.
3. Do not deploy the next move until this step ticks. If you must, say so in
   its commit message, and why.
