---
title: where says which server this is
status: proven
whose_move: rob
check: where_alias
---
Rob has a shell open on many servers at once, and their prompts look alike.
On each of them, and on his laptop, typing `where` prints one sentence saying
which machine this is and what it is for. A user without the alias answers
`where: command not found`, and so does every login whose `.bash_profile`
calls it.

The alias lives in the server user's `~/.bash_aliases`, the same file on
every user, so there is one place to look. The sentence is Rob's own, which
is why the step is his.

The check asks the server whether `~/.bash_aliases` has a line that opens
`alias where=`. It does not read what the alias says.

## How to do it

1. Log in as the site's server user.
2. Add one line to `~/.bash_aliases`, with the site's domain and what the
   site is for:

       alias where="echo 'site.example, the server for the example site'"

3. See that the user's `~/.bashrc` or `~/.bash_profile` reads
   `~/.bash_aliases`, and reads it before any line that calls `where`.
4. Log out, log in again and type `where`.
5. `~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh <shortcode>`, so
   the page shows it.
