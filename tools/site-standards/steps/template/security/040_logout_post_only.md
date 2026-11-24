---
title: Only a POST logs anyone out
status: idea
whose_move: agent
check: security_logout_post_only
---
`GET /logout/` revoked every session the user had. A GET is not covered by
the CSRF check, so a link, an `<img>` or a redirect on another site could
sign a visitor out everywhere. Logging out changes state, so it takes a POST
with the token.

The check reads tracked files. Every logout page (`logout.php` or
`logout/index.php` outside `classes/` and `templates/`) must ask for the
request method and name POST, and no tracked PHP may still link to `/logout`
with an `href`. A site with no logout page passes.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; these are its commits
`Make Logout a form that POSTs` (dc30c1b) and `Log out on POST only`
(10b5ef7). Two commits, in that order, so no deployed state has a Logout
link that does nothing.

1. Wherever a template links to `/logout/` (the menu, the profile page), make
   it a form: `<form action="/logout/" method="POST">`, `<?= $csrf->field() ?>`
   and a button. Style the button to sit where the link did.
2. In the logout page, call `logout()` only when the request method is POST;
   redirect home either way. `prepend.php` has already refused a POST without
   the token.
3. Run the site's gates, then this step's check.

This needs the central CSRF check in `prepend.php` and the template's own
`$csrf`. A site without them does those first.

Live check once Rob has deployed: visiting `/logout/` in the address bar
leaves you logged in; the Logout button logs you out.
