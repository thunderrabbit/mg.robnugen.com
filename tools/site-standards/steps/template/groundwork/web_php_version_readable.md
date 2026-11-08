---
title: Web PHP version readable
position: 25
status: idea
whose_move: agent
check: host_php_probe
---
The PHP that answers the site's web requests can be read without guessing.
`php -v` on the server is the command-line PHP, which is often not the one the
web server runs, so a step that asks "which PHP serves this site?" needs the
web stack itself to answer. On DreamHost the panel says, and the sweep reads
it. On a server with no panel the site answers for itself: a tiny probe file
that prints `PHP_VERSION` to a request from the server's own loopback address
and nothing to anyone else. The EC2 sweep asks for it over loopback and keeps
what it says; the serves-PHP steps read that.

## How to do it

1. Add `wwwroot/_php_version.php` (or the web root's equivalent) that prints
   `PHP_VERSION` as plain text when `REMOTE_ADDR` is `127.0.0.1` or `::1`,
   and answers 404 otherwise. Behind a load balancer the public arrives from
   the balancer's address, so the loopback rule still keeps it private.
2. A bootstrap that runs on every request (an auto-prepended file, a front
   controller, a login redirect) must let `/_php_version.php` through before
   it acts, or the probe answers with a page instead of a version.
3. Deploy it. The next EC2 sweep reads the version through the real vhost:
   over TLS to loopback if the server terminates TLS itself, else over plain
   HTTP with `Host:` and `X-Forwarded-Proto: https` set. A DreamHost site has
   nothing to add; the panel already says.
