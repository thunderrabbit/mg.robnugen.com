---
title: Errors go to the log, not to the visitor
status: idea
whose_move: agent
check: security_errors_hidden
---
`prepend.php` turned `display_errors` on for every visitor, so a warning or an
uncaught exception printed file paths, SQL and function arguments to anyone.
Errors belong in the error log; a site whose owner wants them on screen says
so in its own `Config.php`.

The check reads tracked PHP. Something must turn `display_errors` off, the
only file that turns it on must be one that also turns it off (the switch),
and an exception handler must be set.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its sub-bubble
`HUSHED errors into the log unless Config asks to see them` (cb2b40d), plus
`Log error details instead of printing them` (8b214a3).

One bubble, opened by a BEGIN commit that only raises the site's version.

1. Add `Security\ErrorOutput` with `hide()`, `show()`, `handle()` and
   `logLine()`. Hidden, an uncaught exception is logged as one line (class,
   message, file and line) and the visitor gets a plain 500 page. Leave the
   stack trace out of the log: it carries arguments.
2. Add `public bool $display_errors = false;` to the sample config, and list
   it in the README's config table.
3. In `prepend.php` (and any entry point that runs without it), remove the
   `ini_set('display_errors', ...)` lines and call
   `\Security\ErrorOutput::hide()` straight after the autoloader, before
   Config is read. Once Config is read, call `show()` when it asks. An older
   `Config.php` has no such property; read it with `?? false`.
4. Setup problems and the "could not create Config" message name paths and
   the database: send them to `error_log()` and show a plain 503.
5. Run the site's gates, then this step's check.

Never touch the server's own `Config.php`. What the site's owner will notice:
errors stop printing. Tell Rob, so he knows to read the error log or set
`$display_errors = true` on a site strangers cannot reach.

Live check once Rob has deployed: the pages load, and the site's error log
is where a deliberate error shows up.
