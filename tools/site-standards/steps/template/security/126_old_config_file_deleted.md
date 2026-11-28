---
title: The old config file is gone from the server
status: idea
whose_move: rob
check: old_config_file_deleted
---
The conversion keeps the old config file in the server user's home, as
`<checkout's name>.Config.php.old`, mode 600, so a site can be put back on
it. That copy holds the database password in plain text and nothing reads it
any more. Once the site has run on `site_config.php` long enough for you to
trust it, the copy goes.

The check asks the server, with `test -f` only: done when the checkout runs
on `site_config.php` and the home directory has neither that copy nor a
`Config.php.old` left by a conversion done by hand.

## How to do it

1. Load the site's main pages and look at its error log since the
   conversion.
2. Delete the copy. Either by hand on the server, or from inside the site's
   checkout:
   `~/work/rob/mg.robnugen.com/tools/site-standards/convert-site-config.sh --delete-old`.
   The script deletes only the copy named for the checkout, and only while
   the site's pages answer. A `~/Config.php.old` made by hand is yours to
   delete by hand.
3. The next scan ticks this step.

A session runs `--delete-old` only when you say so: the copy is the way back.
