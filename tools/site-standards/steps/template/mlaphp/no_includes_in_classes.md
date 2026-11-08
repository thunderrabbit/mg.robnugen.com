---
title: Classes do not include code files
position: 120
status: idea
whose_move: agent
check: mlaphp_no_includes_in_classes
---
Chapter 12. No class method pulls in a code file by name (`include
'setup.php'`) to run what is in it; that code becomes a method of its own.
Including a file named by a variable stays allowed, because that is what an
autoloader and a view renderer do.

The check passes when no `include` or `require` under `classes/` names a file
by a literal path.
