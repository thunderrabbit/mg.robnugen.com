---
title: Web PHP version readable
position: 25
status: proven
whose_move: agent
check: host_php_probe
---
The PHP that answers the site's web requests can be read without guessing.
`php -v` on the server is the command-line PHP, which is often not the one the
web server runs, so a step that asks "which PHP serves this site?" needs the
web stack itself to answer. On DreamHost the panel says, and the sweep reads
it. On an EC2 server the sweep reads the Apache PHP module that is enabled and
the release dpkg has installed for it, once for every site on the box; the
serves-PHP steps read that.

## How to do it

Nothing in the site. mg's own copy of `wwwroot/_php_version.php` is not needed
elsewhere, and a site that wants one may add it, but no sweep asks for it.
The step passes once a sweep has read a version for the site's hostname. A
server that runs PHP through php-fpm gets no version until the sweep learns to
read that.
