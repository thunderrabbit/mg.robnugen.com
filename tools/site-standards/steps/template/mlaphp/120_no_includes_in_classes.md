---
title: Classes do not include code files
status: proven
whose_move: agent
check: mlaphp_no_includes_in_classes
---
Chapter 12. No class method pulls in a code file by name (`include
'setup.php'`) to run what is in it; that code becomes a method of its own.
Including a file named by a variable stays allowed, because that is what an
autoloader and a view renderer do.

The check passes when no `include` or `require` under `classes/` names a file
by a literal path.

## How to do it

1. List them: `grep -rnE "\b(include|require)(_once)?\b" classes/`.
2. For each include of a file by name, move the included code into a method
   (of the same class, or a new one) and call it instead. Variables the file
   read from the including scope become parameters; variables it left behind
   become return values.
3. Leave includes of a variable path alone when they are an autoloader or a
   view renderer.
