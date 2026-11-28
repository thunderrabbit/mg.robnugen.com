---
title: The phpMyAdmin script only says to type ql[TAB]
status: proven
whose_move: agent
check: phpmyadmin_script_reminds
model: haiku
---
DreamHost retired phpMyAdmin in November 2026. A site's
`phpmyadmin-open.sh` read the database password from the server and signed
Rob in to it; now it posts a login to a page that is gone.

Rob opens a site's database in DBeaver instead, by typing `ql` and Tab
anywhere inside the site's checkout. That completes to `qlscript_opener`, a
shell function that runs `tools/db-open/db-open.sh` in mg's checkout, one
script for every site. So a site needs no opener of its own.

His fingers still type `phpmyadmin`. Until they stop, the old script stays
as a reminder and nothing else: all of its code goes, and what is left
tells him what to type.

## How to do it

1. List the files: `ls -a | grep -i '^phpmyadmin'`. A site may have more
   than one (`phpmyadmin-open-<name>.sh`), and an editor's backup ending
   in `~`.
2. Replace the whole of each script with exactly this:

       #!/bin/bash
       # DreamHost retired phpMyAdmin. This file only reminds; it goes once
       # ql[TAB] is habit.
       echo "phpMyAdmin is gone. Type ql[TAB] to open this site's database in DBeaver."

   Keep it executable. Do not make it run the opener: Rob asked for a
   reminder, so that he learns the new keys.
3. Delete every backup of an old script (`phpmyadmin-open.sh~`). It still
   holds the old code.
4. See whether git tracks the script: `git ls-files 'phpmyadmin*'`.
   - Tracked: commit it as "Leave only a reminder in phpmyadmin-open.sh".
     If the site's `.gitignore` has a line that un-ignores it, leave that
     line alone.
   - Not tracked (most sites ignore it): there is nothing to commit, and
     this step ends with no commit. Do not start tracking it.
5. If the script sourced a file of ssh settings (`phpscp_creds.sh`) that
   nothing else in the checkout reads, say so to Rob. Do not delete it: a
   deploy script may read it.
6. Leave the site's `README` and `CLAUDE.md` alone unless they tell the
   reader to run the script; change such a line to say `ql[TAB]`.
