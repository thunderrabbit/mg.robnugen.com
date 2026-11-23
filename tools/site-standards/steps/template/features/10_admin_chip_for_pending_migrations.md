---
title: The Admin link counts pending migrations
status: proven
whose_move: agent
check: admin_migrations_chip
---
Schema files from `02` onward wait for an admin to apply them at
`/admin/migrate_tables.php`, and nothing says so unless the admin opens
`/admin/`. A test domain and production drift apart that way. With this step
an admin sees the number of pending migrations inside the menu's Admin link,
on every page that has the menu. Nobody else sees it, and there is no chip at
0.

First built on games.chatforest.com under mg #851, as the bubble that closes
with `NOTIFY Admin when migrations need migratin'`. Read those commits before
starting; the site's design note
`docs/superpowers/specs/2026-11-16-admin-notifications-design.md` says why the
count goes through the Template class.

A pending migration can never be dismissed. The only way one leaves the count
is being applied: no button, no table row and no flag hides it.

The check reads tracked files only. `prepend.php` must set `$admin_count` and
name `getPendingMigrations()`, a `Template.php` under `classes/` must carry
`admin_count`, and a file under `templates/` must draw `$admin_count`.

## How to do it

It is four commits or more, so it is a bubble: a BEGIN commit that only raises
the site's version, then these.

1. **Count it once, in `prepend.php`**, after the login has been worked out:

       $admin_count = 0;
       if ($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()) {
           $admin_count = count($dbExistaroo->getPendingMigrations());
       }

   Call `getPendingMigrations()` again here, on purpose. The list
   `checkaroo()` built is from before its auto-apply, so it is stale on
   exactly the requests that change it. Nobody but an admin is counted, and
   no query runs for anyone else. A site whose API keys or other non-browser
   callers pass through `prepend.php` leaves them at 0 too.

2. **Hand it to every Template.** The Template class takes it in its
   constructor, beside `csrf:`, and sets it after `extract()` so a page
   cannot replace it with `set('admin_count', ...)`:

       private readonly int $admin_count,
       ...
       $admin_count = $this->admin_count;  // after extract()

   Make it a required argument, not a default of 0: a page that forgets it
   must fail PHPStan, not quietly lose the chip. Then pass
   `admin_count: $admin_count` at every `new Template(...)`, and add
   `@var int $admin_count` to the `@var` block of each entry script that
   uses it. A class that builds a Template takes the count as an argument.
   This is one mechanical commit across many files:
   `Hand every Template the admin count`. Nothing draws it yet.

3. **Draw it in the menu**, inside the Admin link so the chip is clicked
   too, with a label that says the number to a screen reader:

       <?php if ($menu_admin_count > 0): ?>
           | <a href="/admin/" aria-label="Admin, <?= $menu_admin_count ?> waiting for an admin">Admin
               <span class="AdminCount" aria-hidden="true"><?= $menu_admin_count ?></span></a>
       <?php else: ?>
           | <a href="/admin/">Admin</a>
       <?php endif; ?>

   where `$menu_admin_count = (int) ($admin_count ?? 0);` and the link
   already shows to admins only. Style `.AdminCount` as a small pill in the
   site's own colours, `display: inline-block` so the link's hover
   underline stays off it. A site with more than one menu or layout draws
   it in each one that has an Admin link.

4. **Test it** in the Unit suite: a `set('admin_count', 99)` does not
   replace the constructor's number; an admin with a count of 3 sees the
   chip and the label; an admin with 0 sees the plain link; a plain user
   and a logged-out visitor never see a count.

5. Run the site's gates, then this step's check, and close the bubble.

A site with no menu, or no Admin link in it, stops and leaves it to Rob.

Live check once Rob has deployed: add a schema file numbered `02` or above
on the test domain, log in as an admin and see the chip on a page other than
`/admin/`; apply it and see the chip go.
