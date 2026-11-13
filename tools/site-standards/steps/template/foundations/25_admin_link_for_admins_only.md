---
title: The Admin Dashboard link is shown to admins only
status: proven
whose_move: agent
check: admin_link_admins_only
---
The home page lists `<a href="/admin/">Admin Dashboard</a>` to every logged-in
user. The `/admin/` pages already refuse anyone who is not an admin, so this is
not a hole. It is still a dead-end link that advertises the admin area to
people who cannot use it. It does not depend on the PHP version, so it sits
ahead of the PHP version track.

Found on base.robnugen.com while logged in as a plain user. The fix there is
two small commits.

The check reads `templates/index.tpl.php`. A site with no such template, or
with no `/admin/` link in it, passes: there is nothing to hide. Otherwise the
link must sit inside an `if` that tests `is_admin`, and `wwwroot/index.php`
must hand that variable to the page.

## How to do it

1. In `wwwroot/index.php`, in the logged-in branch, next to the existing
   `$inner_page->set("username", ...)`, add:

       $inner_page->set("is_admin", $is_logged_in->isAdmin());

   The layout already receives `is_admin`; the inner index template did not.
   Commit it as `Pass is_admin to the home page`.
2. In `templates/index.tpl.php`, wrap the link:

       <?php if (!empty($is_admin)): ?>
           <li><a href="/admin/">Admin Dashboard</a></li>
       <?php endif; ?>

   Commit it as `Show the Admin Dashboard link to admins only`.
3. Run the site's gates, then this step's check.

Some sites build the home page differently (no `$inner_page->set("username"`
line in `wwwroot/index.php`). Find where the page's variables are set and
pass `is_admin` there; if a site already sets `is_admin` for the page, only
the template needs the wrapper. If the page is built in a way this does not
cover, stop and leave it to Rob.

Live check once Rob has deployed: log in as a non-admin and confirm the home
page has no "Admin Dashboard".
