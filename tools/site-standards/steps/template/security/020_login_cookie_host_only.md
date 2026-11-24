---
title: The login cookie belongs to the one host that set it
status: idea
whose_move: agent
check: security_login_cookie
---
A cookie with a Domain attribute goes to every subdomain of that domain, and
any of them may overwrite it. The remember-me cookie is a login: it should
belong to the exact host that set it. Dropping the Domain does that, and the
`__Host-` prefix on the name makes the browser refuse anything else.

The check reads tracked PHP. The file that builds `setcookie()`'s options (the
one with a `'samesite'` key) must have no `'domain'` key, and the cookie's
name must be given the `__Host-` prefix there or where `setcookie()` is
called. A site that sets no cookie from PHP passes.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its sub-bubble
`NARROWED the login cookie to the one host that set it` (70a7f8c).

One bubble, opened by a BEGIN commit that only raises the site's version.

1. In `Auth\CookieOptions::build()`, remove the `domain` key. Fix its tests.
2. Add `CookieOptions::name()`, which puts `__Host-` in front of the
   configured cookie name and leaves a name that already has it alone.
3. Read and write the cookie under that name everywhere: set, kill and the
   lookup that resumes a login.
4. Say in the site's CLAUDE.md and README what the cookie is called now.
5. Run the site's gates, then this step's check.

A site whose login must be shared across subdomains cannot take this step;
stop and leave it to Rob.

What the site's owner will notice: everyone is signed out once, because the
old cookie has another name. Tell Rob before he deploys.

Live check once Rob has deployed: log in, and the browser holds
`__Host-<cookie_name>` with no Domain.
