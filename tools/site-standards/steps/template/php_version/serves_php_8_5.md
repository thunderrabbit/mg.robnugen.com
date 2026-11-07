---
title: Serves PHP 8.5
position: 10
status: proven
whose_move: rob
check: dh_serves_php
param: 8.5
---
Every domain of the site is set to PHP 8.5 in the DreamHost panel, as the
latest sweep saw it. The panel is the authority for what a domain serves;
`php -v` over ssh reports the shell's PHP, which is a different thing.

## How to do it

1. First make sure the code is ready: the tooling and Rector steps for 8.5
   are done and deployed.
2. Lint the deployed tree with the server's PHP 8.5 command-line build.
3. Rob switches each domain to 8.5 in the panel. This is a panel change, so
   it is always Rob's.
4. Load the site's main pages and watch its error log.
5. The next daily sweep ticks this step.
