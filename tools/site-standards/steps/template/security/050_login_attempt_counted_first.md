---
title: A login attempt is counted before its password is checked
status: idea
whose_move: agent
check: security_login_counts_first
---
The login throttle asked "is this username over the limit?", checked the
password, and only then recorded a failure. A burst of parallel guesses all
read "under the limit" before any of them was written down. On the template,
30 parallel wrong passwords were all checked; with this step 5 were checked
and 25 were throttled.

The check reads tracked PHP. The class that reads `login_attempts` must have
an `admit()` method, the login path must call it, and nothing outside the
tests may still call `isThrottled()`.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; these are its commits
`Add LoginThrottle::admit(), which counts first` (5c8ce17) and
`Count a login attempt before checking its password` (84a214c).

1. Add `LoginThrottle::admit($username, $ip_address): bool`. It records the
   attempt as a failure first and counts second, with its own row in the
   count, so "over" means past the limit and not at it. An attempt over the
   limit takes its own row back out, so being refused does not lengthen the
   lock. Unit-test both sides of the limit.
2. In the login path, call `admit()` once, before the password is looked at,
   in place of `isThrottled()` and the later "record a failure". A correct
   password still clears the user's failures.
3. Run the site's gates, then this step's check.

A site with no login throttle has an earlier job: the auth hardening that
adds one.

Live check once Rob has deployed: a wrong password is refused, the right one
still logs in, and too many wrong ones lock the username for the window.
