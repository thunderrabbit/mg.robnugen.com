---
title: Test domain serves PHP {target}
position: 4
status: idea
whose_move: rob
check: dh_test_serves_php
param: target
---
The site's test domain, and only that domain, serves the site's target PHP,
as the latest sweep saw it. This lets the test suite run against the new
PHP before any real visitor meets it. A site with no test domain has nothing to
switch and skips this step.

## How to do it

1. The code step must be done and deployed to the test domain first.
2. Rob switches only the test domain, so it is always his move:
   - **DreamHost:** in the panel.
   - **EC2:** a test domain on the same box as production can't move without
     production, so it moves to a box that serves the target, by the plan
     for that box.
3. The next sweep ticks this step.
