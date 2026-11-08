---
title: Repo located
position: 30
status: proven
whose_move: rob
check: repo_located
---
The site's code is in a GitHub repo, and a checkout of it sits under `~/work`
on Lemur 13. Both are recorded here. Many sites exist only on their server;
until the code is somewhere it can be branched and checked, no other step can
be worked.

## How to do it

1. Find the repo on GitHub. If the code only lives on the server, make a repo
   from it first; that decision is Rob's.
2. Clone it under `~/work` on Lemur 13.
3. Record both:
   `tools/site-standards/site.sh site-set --shortcode=<shortcode> --github=<owner>/<name> --checkout=<path under ~/work>`
4. If the site's work happens on a branch, not the trunk, record it:
   `--upgrade=<branch>`. The scanner then checks the checkout at that
   branch's tip. An agent running `/next-mg-step` records this itself.
