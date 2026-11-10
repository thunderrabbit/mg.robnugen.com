---
title: print_roblog() is moved into a class
position: 39.0002
status: proven
whose_move: agent
check: mlaphp_print_roblog_in_class
---
Chapter 4, ported. `print_roblog()` is the log-file sibling of `print_rob()`:
a loose function in `prepend.php` that appends a line to `~/rob.log`. Rob
chose its new home once, as `\PrintRob\Debug::printRobLog()`, next to
`printRob()` in `classes/PrintRob/Debug.php`, so on every other site an agent
makes the same move without him. Whatever loose functions a site has besides
this one stay Rob's, in the next step.

The template never had `print_roblog()`, so most sites pass at once. Some
older sites have their own, with a different signature (one returns `bool`
and takes `bool $include_timestamp`). Each site keeps its own signature and
body; only the name and home are Rob's choice.

The check looks in every tracked PHP file outside `vendor/` for
`print_roblog(`: its definition, a call, or a commented-out call. A docblock
line may still say where the function came from. A site that never had
`print_roblog()` passes.

## How to do it

1. Do the `print_rob()` step first, so `classes/PrintRob/Debug.php` exists.
2. Add `printRobLog()` to `\PrintRob\Debug` as a `public static` method,
   after `printRob()`. Copy the site's `print_roblog()` signature and body
   unchanged, and give it a docblock saying what it writes and where. Leave
   the class docblock alone: Rob wrote it. Commit it on its own (`Add
   PrintRob\Debug::printRobLog()`), with the old function still in place.
3. If the body finds the log file from `__DIR__`, check it still lands in the
   same place now that it runs from `classes/PrintRob/`. A pattern that keeps
   only `/home/<user>` does; anything relative to the project root needs the
   path adjusted, and that change goes in this commit's message.
4. Delete `function print_roblog(...)` from `prepend.php` (and from anywhere
   else it is defined). Change every `print_roblog(` left, live or commented
   out, to `\PrintRob\Debug::printRobLog(`, and CLAUDE.md's mentions with it.
5. Run the site's gates, then commit step 4 (`Drop the loose print_roblog()
   from prepend.php`) and run this step's check.

Do not pick another name. `PrintRobLog` fails PSR-12 (a method is camelCase),
and the `print_rob()` step lists the other names that were tried.
