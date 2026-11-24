---
title: Rob update DH to PHP 8.6 for this site
status: proven
whose_move: rob
check: dh_serves_php
param: 8.6
---
Every domain of the site serves PHP 8.6 or newer, as the latest sweep saw it.
Only Rob sets the PHP a site is served by.

Everything above this step in the track works on PHP 8.5 and does not wait
for it. The one step below, the PHP 8.6 floor, makes the site answer 503 on
anything older, so it must not be deployed before this step is done.

## How to do it

1. First the code must be ready for 8.6: the PHP version track's steps for
   this site (it compiles on the new PHP, and for a site with a test domain,
   the test domain serves it and the safe suite passes there).
2. Switch the PHP. This is always Rob's:
   - **DreamHost:** Rob switches each domain to 8.6 in the panel.
   - **EC2:** the PHP comes with the box. The site moves to a box that serves
     8.6, by the plan for that box.
3. Load the site's main pages and watch its error log.
4. The next sweep ticks this step.
