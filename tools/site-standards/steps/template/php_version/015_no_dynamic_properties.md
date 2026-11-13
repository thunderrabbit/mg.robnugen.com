---
title: Classes declare every property they use
position: 15
status: idea
whose_move: agent
check: no_dynamic_properties
---
No class creates a property it doesn't declare. PHP 8.2 deprecates it, and
PHP 9 makes it an error. PHPStan reports each one as "Access to an undefined
property"; the check counts those from a run plus the ones the site's
baselines hide. `stdClass`, classes with `__get`/`__set`, and classes marked
`#[\AllowDynamicProperties]` are allowed.

PHPStan below level 2 looks only at properties on `$this`, so at levels 0
and 1 a clean count can't tell, and the step says so rather than ticking.

This is the biggest piece of 8.2's changes for older code, and it has to be
done before the site serves anything past 8.1.

## How to do it

1. List them: the baseline entries with `identifier: property.notFound`, plus
   a PHPStan run.
2. Declare each property on its class, with a type when the code shows one,
   and its default where the code relied on it being unset (`= null`).
3. A class that really is a bag of values (rows copied onto an object, say)
   gets `#[\AllowDynamicProperties]` with a comment saying why, rather than a
   hundred declarations. Rob decides which classes those are.
4. Remove each finding from the baseline as it is fixed, run the Unit suite on
   the current PHP's runner and on the target's, and commit a class or a group
   at a time.
