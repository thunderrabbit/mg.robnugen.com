---
title: Every hotfix on the live line is on the upgrade line
status: proven
whose_move: agent
check: js_hotfixes_ported
---
A site preparing a long upgrade on its own branch (the one named with
`site-set --upgrade=`) keeps taking hotfixes on the line its server runs.
Every one of those has to land on the upgrade line too, or promoting it will
quietly undo a fix that is already in production. A site with one line passes
at once.

The check lists the commits reachable from what the server runs that the
upgrade branch does not have, by patch, and lets one through only when it is
a `BEGIN` commit, says `(cherry picked from commit X)` with X on the upgrade
line, carries a `<Line>-has: X` trailer naming the upgrade-line commit that
solved it differently, or shares an author date and subject with a commit
there (a pick rebased on either side). The names are described in
`tools/site-standards/NAMES.md`.

## How to do it

1. Write every fix on the upgrade line first, then `git cherry-pick -x` it
   onto the live line, so the live commit says where it came from.
2. When the upgrade line solved the same problem another way, put
   `<Line>-has: <sha>` in the live commit's body, naming that commit
   (`Green-has: 0d225bc` on a site whose upgrade line is called green).
3. Run the check by hand before promoting:
   `SITE_UPGRADE_BRANCH=<branch> SITE_SERVER_COMMIT=<sha> checks/js_hotfixes_ported.sh <repo>`.
