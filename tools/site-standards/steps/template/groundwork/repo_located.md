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
4. Record which branch is deployed, if it is not `master`: `--deployed=<branch>`.
   The scanner checks the checkout only when it is on that branch.
