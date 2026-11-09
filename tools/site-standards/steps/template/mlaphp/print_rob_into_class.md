---
title: print_rob() is moved into a class
position: 39.0001
status: proven
whose_move: agent
check: mlaphp_print_rob_in_class
---
Chapter 4, ported. The new-DH-user-site template's one loose function is
`print_rob()` in `prepend.php`. Rob chose its new home once, as
`\PrintRob\Debug::printRob(mixed $object, bool $exit = true): void` in
`classes/PrintRob/Debug.php`, so on every other site an agent makes the same
move without him. Whatever loose functions a site has besides this one stay
Rob's, in the next step.

The check looks in every tracked PHP file outside `vendor/` for `print_rob(`:
its definition, a call, or a commented-out call. A docblock line may still
say where the function came from. It also wants `classes/PrintRob/Debug.php`
to define `printRob()`. A site that never had `print_rob()` passes.
`print_roblog()` is a different function and is not part of this step.

## How to do it

1. Copy `classes/PrintRob/Debug.php` byte for byte from a site that already
   passes this step (`ls ~/work/rob/*/classes/PrintRob/Debug.php`). Rob
   wrote its docblock himself: never reword it, and never change the body,
   which is the old function's unchanged. Commit it on its own (`Add
   PrintRob\Debug::printRob()`), with the old function still in place.
2. Delete `function print_rob(...)` from `prepend.php` (and from anywhere
   else it is defined).
3. Change every `print_rob(` left, live or commented out, to
   `\PrintRob\Debug::printRob(`. The template has one, commented out, in
   `classes/Database/DBExistaroo.php`; a call that passed `false` keeps it.
4. Change CLAUDE.md's mentions of `print_rob()` to
   `\PrintRob\Debug::printRob()`. Leave `print_roblog()` alone.
5. Run the site's gates, then commit steps 2 to 4 together (`Drop the loose
   print_rob() from prepend.php`) and run this step's check.

Do not pick another name. These were tried and fail: `\Debug\Print`
(`print` is reserved, so PHP will not parse it), `\Debug::PrintRob`
(PSR-12 wants a class in a namespace, and a method in camelCase), and a
method named `Rob`.
