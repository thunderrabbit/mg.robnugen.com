---
title: New passwords are at least 15 characters
status: idea
whose_move: agent
check: security_password_policy
---
NIST SP 800-63B-4's rules for a password that is the only factor: at least 15
characters, each Unicode code point counting as one; no composition rules
("one digit, one symbol"); and the whole password is verified, never a
truncated one. The rules are for new passwords only. Login never asks, so an
account whose password predates this still works.

The check reads tracked PHP. A `PasswordPolicy.php` must set a `MIN_...`
constant of 15 or more, and some other file must ask `PasswordPolicy::`.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its sub-bubble
`LENGTHENED passwords to NIST's fifteen characters` (84bbfda).

One bubble, opened by a BEGIN commit that only raises the site's version.

1. Add `Auth\PasswordPolicy` with `problems($password): list<string>`, the
   words for the person typing, empty when the password is acceptable. While
   the site hashes with bcrypt, a password over 72 bytes is refused, not cut:
   bcrypt reads 72 bytes and silently ignores the rest. Unit-test it.
2. Hold new passwords to it at registration and on a password change.
   Remove any older length or composition rule.
3. Tell people the length before they type, on both forms.
4. Rehash an old hash at login with `password_needs_rehash()`, the one moment
   the plain password is in hand.
5. Document the policy in the site's CLAUDE.md.
6. Run the site's gates, then this step's check.

Not part of this step: NIST's check of new passwords against a list of
known-breached ones.

Live check once Rob has deployed: a 14-character new password is refused
with the reason, a 15-character one is taken, and an account with an older,
shorter password still logs in.
