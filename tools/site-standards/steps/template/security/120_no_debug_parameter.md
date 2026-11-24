---
title: No page answers ?debug in the URL
status: idea
whose_move: agent
check: security_no_debug_parameter
model: sonnet
---
The home page answered `?debug=N` to anyone, logged in or not, by printing
before the page. It did nothing useful, and the docs described a debug mode
that did not exist. What a visitor types in the URL should never change what
a page reveals. Errors on screen are `$display_errors` in `Config.php`, from
the errors step.

The check reads tracked PHP for a request parameter named `debug`
(`$_GET['debug']`, `$_REQUEST['debug']` or a request object's
`->get['debug']`).

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its commit
`Drop the ?debug parameter` (71f0493). One commit.

1. Remove the code that reads the parameter and whatever it printed.
2. Remove the lines about `?debug=1` from the site's CLAUDE.md and README.
3. Run the site's gates, then this step's check.

If a site's `?debug` does real work that someone uses, stop and leave it to
Rob: it should move behind an admin login, which is a different change.
