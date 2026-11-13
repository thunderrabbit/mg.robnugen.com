---
title: Unused print_robs() is removed
position: 38.9996
status: proven
whose_move: agent
check: mlaphp_unused_function_removed
param: print_robs
---
Chapter 4, by removal. `print_robs()` is a loose function in `prepend.php`
that calls `print_rob()` on each of its arguments and exits if the last one is
true. Nothing calls it on the sites that have it, so Rob chose to delete it
rather than find it a home in `\PrintRob\Debug`. No dead-code tool on these
sites would do that for him: neither PHPStan nor the sites' Rector sets remove
a loose function that has no callers.

The new-DH-user-site template never had `print_robs()`, so most sites pass at
once.

The check looks in every tracked PHP file outside `vendor/` for `print_robs(`:
its definition, a call, or a commented-out call. A docblock line is not
counted. A site that never had `print_robs()` passes.

## How to do it

1. List every mention: `git grep -nE '\bprint_robs\s*\(' -- '*.php' ':!vendor'`,
   and `git grep -n "print_robs" -- ':!vendor'` for a mention outside PHP (a
   callable written as a string, a script, CLAUDE.md).
2. **If anything live calls it, stop.** A call that is not commented out, in
   any file or on any branch the server runs, means this site uses it. Tell
   Rob; he gives it a home or marks the step NAh. Do not delete a function
   that is called, and do not comment out a caller to make this step pass.
3. Delete the function's definition and its docblock, wherever it is defined.
4. Delete each commented-out call. If the comment line holds nothing else,
   the line goes; if it holds other commented-out code, only the call goes.
5. Take it out of CLAUDE.md and any other doc that lists the site's debug
   helpers.
6. Run the site's gates, commit (`Remove the unused print_robs()`), and run this
   step's check.
