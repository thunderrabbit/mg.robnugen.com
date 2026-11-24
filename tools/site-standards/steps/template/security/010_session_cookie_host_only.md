---
title: The session cookie is one no sibling site can plant
status: proven
whose_move: agent
check: security_session_cookie
---
The PHP session holds the CSRF token. Under PHP's default cookie name,
`PHPSESSID`, any sibling subdomain can set a session id for the parent domain,
so it can choose a visitor's session and then knows their token. A cookie
whose name starts with `__Host-` is accepted by the browser only over HTTPS,
with `Path=/` and no Domain, and is sent to this host alone.

This is the first and most important step of the track, and it does not need
a new PHP.

The check reads tracked PHP. Every `session_start()` call must be in one
file, and that file must name the cookie with the `__Host-` prefix and set
`cookie_secure`. A site that never starts a session passes.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its sub-bubble
`HOSTED the session cookie where no sibling site can plant it` (4124e9d, on
the branch `php86-security` until it reaches master). Read those commits
first.

One bubble, opened by a BEGIN commit that only raises the site's version.

1. Add `Auth\Session` with `options()` and `start()`. The options are the
   name `__Host-session`, `cookie_secure`, `cookie_httponly`,
   `cookie_samesite` Lax, `cookie_path` `/`, an empty `cookie_domain`,
   `use_strict_mode` and `use_only_cookies`. Say them all: a host's php.ini
   may say otherwise. Unit-test `options()`.
2. Replace every bare `session_start()` with `\Auth\Session::start()`:
   `prepend.php`, any entry point that runs without it (`/login/register.php`
   on the template), and the fresh session a logout opens.
3. Document the class in the site's CLAUDE.md.
4. Run the site's gates, then this step's check.

What the site's owner will notice, so tell Rob before he deploys: a form left
open across the deploy answers 403 once, because its token was in the old
session. A site served over plain HTTP gets no session at all, so no form
works there; that is intended.

Live check once Rob has deployed: the browser holds `__Host-session` and no
`PHPSESSID`, and a form still submits.
