---
title: An unknown username takes as long as a wrong password
status: proven
whose_move: agent
check: security_unknown_user_timing
---
For a username that exists, login hashes the typed password to compare it.
For one that does not, it answered at once. The difference shows on a
stopwatch whatever the page says, and tells a guesser which usernames are
real.

The check reads tracked PHP. The file that checks a password for a username
must call `password_verify()` twice: once for the stored hash, and once
against a stand-in hash when there is no such user. A site with no
`password_verify()` passes.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its commit
`Take as long over an unknown username as a wrong password` (b51ff9f), and
later `Time a missing user against an argon2id hash` (e69966f).

1. Where the login path finds no row for the username, call
   `password_verify()` on the typed password against a constant hash of
   something nobody can type, throw the answer away, and refuse as before.
2. The stand-in hash must be of the algorithm and cost real users have, or
   the two cases still differ. A site that has done the argon2id step keeps
   one stand-in per algorithm; one that has not uses a bcrypt hash at PHP's
   default cost.
3. Run the site's gates, then this step's check.
