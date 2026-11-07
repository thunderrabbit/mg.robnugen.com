---
title: Test domain serves PHP 8.5
position: 4
status: idea
whose_move: rob
check: dh_test_serves_php
param: 8.5
---
The site's test domain, and only that domain, is set to PHP 8.5 in the DreamHost
panel, as the latest sweep saw it. This lets the test suite run against the new
PHP before any real visitor meets it. A site with no test domain has nothing to
switch and skips this step.

## How to do it

1. The code step must be done and deployed to the test domain first.
2. Rob switches only the test domain to 8.5 in the panel. This is a panel change,
   so it is always Rob's.
3. The next daily sweep ticks this step.
