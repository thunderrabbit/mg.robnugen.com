---
title: The site refuses to run below PHP 8.6
status: proven
whose_move: agent
check: security_php_floor
param: 8.6
model: sonnet
---
PHP 8.6 made sessions strict, HttpOnly and SameSite=Lax by default, and the
template was reviewed against that behaviour. A site that says so in code
stops, with a 503, on any older PHP, instead of serving pages nobody checked
there. That covers a domain switched back in the panel, a new server and a
restored backup alike.

**Do not start this before the step above is ticked.** On a site still
served by 8.5 this turns every page into a 503.

The check reads tracked PHP. `prepend.php` must call `requireFloor()`, the
class that defines it must hold a `PHP_VERSION_ID` of 80600 or more, and a
`register.php` that runs without `prepend.php` must call it too.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; these are its commits
`Add Version\Php, the PHP 8.6 floor` (2ca1785) and
`Refuse to run below PHP 8.6` (d4132a9).

1. Add `Version\Php` with `FLOOR_ID = 80600`, `FLOOR = '8.6'`,
   `meetsFloor(int $version_id = PHP_VERSION_ID)` and `requireFloor()`, which
   logs the running version, answers 503 with a line that names only the
   floor, and exits. Unit-test `meetsFloor()` on both sides of the floor.
2. Call `\Version\Php::requireFloor()` first in `prepend.php`, straight after
   the autoloader, and in any entry point that runs without `prepend.php`.
3. Use no 8.6-only syntax, and leave `composer.json`, `phpstan.neon` and the
   commit hook on the PHP the gates run on: they may trail the runtime floor
   while no runner image exists for the newest PHP. Say so in the site's
   CLAUDE.md.
4. Run the site's gates, then this step's check.

Live check once Rob has deployed: the pages load. If any answers
"This site needs PHP 8.6 or later", that domain is not on 8.6; tell Rob at
once.
