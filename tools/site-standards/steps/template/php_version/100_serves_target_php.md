---
title: Serves PHP {target}
position: 100
status: proven
whose_move: rob
check: dh_serves_php
param: target
---
Every domain of the site serves its target PHP, or a newer one, as the latest
sweep saw it. On DreamHost the panel is the authority for what a domain
serves; `php -v` over ssh reports the shell's PHP, which is a different
thing. On EC2 the sweep reads the PHP the web server actually loads.

## How to do it

1. First make sure the code is ready: the tooling step, and the steps above
   this one (the code compiles on the target and the current PHP, and for a
   site with a test domain, the test domain is on the target and its safe
   suite passes), are done and deployed.
2. Lint the deployed tree with the server's own command-line build of the
   target PHP.
3. Switch the PHP. This is always Rob's:
   - **DreamHost:** Rob switches each domain to the target in the panel.
   - **EC2:** the PHP comes with the box. The site moves to a box that serves
     the target (a newer OS, or a newer PHP package), by the plan for that
     box. Raise the site's `php_target` only after this step is done.
4. Load the site's main pages and watch its error log.
5. The next sweep ticks this step.
