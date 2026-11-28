---
title: The phpMyAdmin reminder script is gone
status: idea
whose_move: agent
check: phpmyadmin_script_removed
model: haiku
---
The reminder left by the step before this one is for Rob's fingers. He
expects them to know `ql[TAB]` by the end of January 2027. Do not do this
step for any site before 2027-01-27, and not until Rob says his fingers
have stopped typing `phpmyadmin`.

## How to do it

1. List the files: `ls -a | grep -i '^phpmyadmin'`.
2. A tracked one: `git rm` it, and remove any line in `.gitignore` that
   un-ignores it (`!phpmyadmin-open.sh`). Commit as "Remove the phpMyAdmin
   reminder script".
3. One git does not track: delete it. There is nothing to commit.
4. Remove any mention of the script from the site's `README` and
   `CLAUDE.md`.
