---
title: Reminders for admins, kept in the site and counted in the Admin link
status: proven
whose_move: agent
check: admin_notifications
---
This builds on `admin_chip_for_pending_migrations`: do that step first. First
built on games.chatforest.com under mg #851, as the two bubbles after that
one (`COUNTIN Notifications`, then `SUCCESSFULLY added notifications for
admins`). Read those commits and the site's design note
`docs/superpowers/specs/2026-11-16-admin-notifications-design.md` before
starting.

The check reads tracked files only. A schema file under `db_schemas/` must
create the table `notifications`, `prepend.php` must set `$admin_count` and
name notifications, and an `admin/notifications/index.php` must exist under
the web root.

## What it is

- **The table is admin-only.** No `user_id` column: it is not a message to
  one account. Columns are `notification_id`, `message`, `link` (optional, a
  path on this site), `show_from`, `completed_at`, `completed_by` and
  `created_at`, with a key leading on `completed_at`.
- **Due** means `show_from` at or before now and `completed_at` empty. Only
  due rows are counted. A row for next April costs nothing until April.
- **Every time is UTC, written from PHP.** The database clock is not UTC on
  every server, so never `NOW()` from the application.
- **Pending migrations are never rows here** and can never be completed. The
  count is pending migrations plus due notifications; the page says the only
  way to clear a migration is to apply it.
- **A missing table counts nothing and stops no page.** The count runs on
  every admin request, so a database error there returns 0.

## How to do it

Two bubbles, each opened by a BEGIN commit that only raises the site's
version.

1. **Table and count.**
   - Add `create_notifications.sql` to the site's `01_` schema directory, so
     the table is applied on the next request and ships with the code that
     reads it. It is created empty. Keep semicolons out of its comments:
     the migration runner splits on them.
   - Add a class (`Admin\Notifications` on the first site) that takes the
     PDO handle and an optional "now", with `dueCount()`. Unit-test it.
   - In `prepend.php`, add `dueCount()` to the admin's `$admin_count`.
   - A site's own first rows go in a later, numbered migration of its own,
     never in the shared `01` file.
2. **The page.**
   - `listDue()`, `listUpcoming()`, `complete($notification_id, $user_id)`
     and `remind($date, $message, $link)` on the same class. `complete()`
     is true only when this call did it, and only for a due row.
     `remind()` refuses a bad date, an empty or over-long message, and a
     link that is not a path on this site.
   - `/admin/notifications/` for admins only: the due list with a Completed
     button each, the "Remind me" form, and what is still upcoming so a typo
     is seen before its day. Every form carries `<?= $csrf->field() ?>`.
     Messages and links are escaped; a link is drawn only when it is a path
     on this site.
   - Link the page from `/admin/` with its due count.
3. Run the site's gates, then this step's check.

Rob applies nothing by hand for the `01` file. Any later migration with the
site's own rows is his to apply at `/admin/migrate_tables.php`, where the
chip will already be counting it.

Live check once Rob has deployed: add a reminder dated today, see the count
rise on another page, press Completed and see it fall.
