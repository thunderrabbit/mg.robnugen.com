---
title: Passwords are hashed with argon2id
status: idea
whose_move: agent
check: security_argon2id
---
bcrypt reads the first 72 bytes of a password and ignores the rest. argon2id
reads all of it, so the 72-byte ceiling of the password policy can go. This
depends on the served PHP being built with argon2id, not on its version, so a
site falls back to bcrypt where it is missing and keeps working.

Do the password policy step first.

The check reads tracked PHP. Every file that calls `password_hash()` must
name argon2id, and something must call `password_needs_rehash()`. A site that
hashes no password passes.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; these are two bubbles on the
branches `argon2id-on-admin` (f6f16e9) and `argon2id-passwords`
(`ARGONED passwords, and let them run past 72 bytes`, 30e850e).

1. **Ask the served PHP first.** Add `Auth\Argon2id`, which says whether
   `password_algos()` lists argon2id and times one hash, and show its answer
   on `/admin/` as "Password hashing: ...". Rob deploys this and reads the
   line: the command-line PHP is not the one that serves the site.
2. **Then hash with it.** One bubble, opened by a BEGIN commit that only
   raises the site's version:
   - Add `Auth\PasswordHash`: `algorithm()` (argon2id where listed, bcrypt
     otherwise), `make()`, `needsRehash()` and `absentUserHash()`, with one
     stand-in hash per algorithm. Nothing else calls `password_hash()` or
     `password_needs_rehash()`.
   - Hash every new password through it. `password_verify()` reads the
     algorithm out of the stored hash, so old bcrypt hashes still verify, and
     login replaces them.
   - Time a missing user against a hash of the algorithm in use.
   - Under argon2id, raise the policy's maximum to 1024 characters; keep the
     72-byte refusal for the bcrypt fallback.
   - Document it in the site's CLAUDE.md.
3. Run the site's gates, then this step's check.

Live check once Rob has deployed: `/admin/` says argon2id is in use, and
after logging in, that user's `password_hash` starts with `$argon2id$`.
