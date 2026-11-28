---
title: The server runs on site_config.php
status: proven
whose_move: agent
check: server_on_site_config
---
The code can read `site_config.php` since the step before, but the server
still has the old class file, so every check `Config\Settings` makes is held
back by the old file's leniency: a key it does not know is dropped, not
refused. This step moves the server over. Nothing is committed in the site's
repo.

The move is one script, `tools/site-standards/convert-site-config.sh` in mg's
checkout, because a list of commands with a rollback is where a comparison
gets skipped. It writes the new file from the old one's values, and after
every change compares a hash of the settings the site's own loader builds. A
comparison that fails puts the site back on its old file. It never prints a
config value. The old file is kept in the server user's home, mode 600, named
for the checkout.

The check asks the server, with `test -f` only: done when the checkout has
`site_config.php` and no longer has the old file (`Loader`'s `LEGACY_FILE`).
DreamHost servers only; any other gets "could not tell".

## How to do it

Run everything from inside the site's checkout, clean and at the commit the
server runs. Each run prints one JSON object; show its `say` as it is.

1. `~/work/rob/mg.robnugen.com/tools/site-standards/convert-site-config.sh --dry-run`.
   Go on only when its `outcome` is `would_convert`. `not_ready` names what
   is in the way; when that is a key `Settings` does not know, the code step
   missed it, and that is a commit in the site's repo, not something to work
   around here.
2. A site with a test domain converts that first: the same command with
   `--test --dry-run`, then `--test`. Go on only when it says `converted`.
3. The same command with no option. Its `outcome` decides:
   - `converted`: go on.
   - `put_back`: the site runs on its old file, as before. Say which
     comparison failed and stop; nothing here is to be fixed by hand on the
     server.
   - `needs_a_person`: stop and tell Rob at once, with the script's words.
4. Run this step's check.
5. `~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh <shortcode>`, so
   the page shows it.

Never run a command on the server yourself for this step, and never open
either config file or the kept copy: they hold the database password. Never
run `--delete-old`; the next step is Rob's.
