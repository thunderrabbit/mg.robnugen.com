---
title: Composer installs for the PHP the server runs
position: 5
status: proven
whose_move: agent
check: composer_platform_pinned
---
`composer.json` pins Composer's platform PHP (`config.platform.php`) to the
PHP the site's server serves today, and `composer.lock` was resolved with
that pin. Composer then picks only packages the server can run, whatever PHP
the machine running Composer has. Without the pin, a lock made on a newer
PHP (a laptop, or the target's runner image) can bring in a package that
fails on the server.

This is for a site that deploys Composer packages. A site whose `require`
names only PHP and its extensions keeps `vendor/` for dev tools that never
reach the server, so it has nothing to pin and passes with a line saying so.

When the server moves to a newer PHP the pin moves too, and this step opens
again until it does. Same major.minor is enough (`8.1.2` and `8.1` both hold
to 8.1).

## How to do it

1. Find what the server serves: the site's row on the standards page, or
   `SITE_PHP_CURRENT` in a check. Use the full version if you know it
   (`8.1.2`).
2. Add it to `composer.json`:

       "config": { "platform": { "php": "8.1.2" } }

3. Re-resolve the lock without upgrading anything:
   `composer update --lock`, in the stock composer image if the machine has no
   composer. Read the lock diff: a package that moves backwards was too new for
   the server.
4. A `conflict` rule that exists only to keep a package off a release needing
   a newer PHP is this pin written by hand, and nothing ever reopens it.
   Delete the rule once the pin is in and check the lock does not move: the
   pin now does that job.
5. Run the Unit suite, then commit both files together.
6. After the server moves to a newer PHP, change the pin to that PHP and
   repeat step 3. Then let go of what the old pin held back: `composer
   outdated` lists it, `composer update <package>` takes each one, and
   anything the site calls through an updated package is tried once for real
   before the lock is deployed.
