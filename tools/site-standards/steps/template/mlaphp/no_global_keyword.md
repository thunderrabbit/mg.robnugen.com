---
title: No global keyword
position: 50
status: idea
whose_move: agent
check: mlaphp_no_global
---
Chapter 5. No function or method reaches into the global scope with `global
$x` or `$GLOBALS['x']`. What it needs is passed in: as a constructor argument
for a class, or as a parameter for a function.

The check passes when no PHP file outside `vendor/` uses the `global` keyword
or `$GLOBALS`. No site has done this yet, so there is no how-to.
