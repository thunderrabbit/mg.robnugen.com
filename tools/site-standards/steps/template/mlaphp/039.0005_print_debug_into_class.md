---
title: print_debug() is moved into a class
position: 39.0005
status: proven
whose_move: agent
check: mlaphp_print_debug_in_class
---
Chapter 4, ported. `print_debug()` is an older sibling of `print_rob()`, on
the sites that descend from the code `print_rob()` first appeared in: a loose
function in `prepend.php` that, while `PRINT_DEBUG_ACTIVE` is on, queues a
`print_r()` of its argument in the session for a later page to show. Rob
chose its new home as `\PrintRob\Debug::printDebug()`, next to `printRob()`
in `classes/PrintRob/Debug.php`. Whatever loose functions a site has besides
this one stay Rob's, in the `Functions are moved into classes` step.

The new-DH-user-site template never had `print_debug()`, so most sites pass
at once. The sites that have it differ: one takes the `\Mlaphp\Request` as
its first argument and writes to its session, another writes to `$_SESSION`
itself. Each site keeps its own signature and body; only the name and home
are Rob's choice.

The check looks in every tracked PHP file outside `vendor/` for
`print_debug(`: its definition, a call, or a commented-out call. A docblock
line may still say where the function came from. A site that never had
`print_debug()` passes.

## How to do it

1. Do the `print_rob()` step first, so `classes/PrintRob/Debug.php` exists.
2. Add `printDebug()` to `\PrintRob\Debug` as a `public static` method, after
   the methods already there. Copy the site's `print_debug()` signature and
   body unchanged, constants and all, and give it a docblock saying what it
   queues and where. Leave the class docblock alone: Rob wrote it. Commit it
   on its own (`Add PrintRob\Debug::printDebug()`), with the old function
   still in place.
3. Delete `function print_debug(...)` from `prepend.php` (and from anywhere
   else it is defined). Change every `print_debug(` left, live or commented
   out, to `\PrintRob\Debug::printDebug(`, keeping each call's arguments, and
   CLAUDE.md's mentions with it.
4. Run the site's gates, then commit step 3 (`Drop the loose print_debug()
   from prepend.php`) and run this step's check.

Do not pick another name, and do not change what the function does: where
the queue is read back is not part of this step.
