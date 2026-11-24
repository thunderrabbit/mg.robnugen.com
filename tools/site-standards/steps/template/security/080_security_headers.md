---
title: Every page sends the security headers
status: proven
whose_move: agent
check: security_headers
model: sonnet
---
Five response headers every page should carry, sent from one place so no page
has to remember them:

- `X-Content-Type-Options: nosniff`
- `Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'`
- `X-Frame-Options: DENY`, for browsers older than `frame-ancestors`
- `Referrer-Policy: same-origin`
- `Strict-Transport-Security: max-age=31536000`

There is no `script-src`: the templates carry inline scripts, and a policy
that allowed those would promise more than it delivers. HSTS has no
`includeSubDomains`: a site must not decide that for hosts it does not run.

The check reads tracked PHP and `.htaccess` for the names
`X-Content-Type-Options`, `frame-ancestors`, `Referrer-Policy` and
`Strict-Transport-Security`. It does not read their values.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; these are its commits
`Add Security\Headers, the headers every page sends` (bde7f02) and
`Send the security headers from both entry points` (21501f8).

1. Add `Security\Headers` with `all()` (header name to value) and `send()`.
   Unit-test `all()`.
2. Call `\Security\Headers::send()` in `prepend.php` before any output, so
   the error pages carry them too, and in any entry point that runs without
   `prepend.php`.
3. Document them in the site's CLAUDE.md.
4. Run the site's gates, then this step's check.

A site that is meant to be framed by another site, or that serves any page
over plain HTTP, cannot take these values as they stand; stop and leave it
to Rob.

Live check once Rob has deployed: `curl -sI` on the home page shows all
five.
