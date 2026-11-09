---
title: Functions are moved into classes
position: 40
status: proven
whose_move: rob
check: mlaphp_functions_in_classes
---
Chapter 4. Loose functions defined in page scripts and include files move into
classes as static methods, so the autoloader can find them and nothing has to
be included to get a function. Class files that were scattered outside
`classes/` move into it.

The check counts named functions defined at the start of a line in any PHP
file outside `classes/`, `Tests/`, `vendor/` and `templates/`. Closures and
methods with a visibility keyword are not counted.

## How to do it

This step is Rob's: how code is grouped and named is a matter of taste, so
an agent never does it alone. With Rob, one group at a time:

1. List them: `tools/site-standards/checks/mlaphp_functions_in_classes.sh
   <repo>` names the first; `git ls-files -z '*.php' ':!:classes/**'
   ':!:Tests/**' ':!:vendor/**' ':!:templates/**' | xargs -0 grep -nE
   '^\s*function\s+\w+\s*\('` lists them all.
2. The agent proposes a shape for one group: the class it moves into, its
   name, and the static method names, with the old function left as a one-line
   call for one commit while callers move. Rob picks or changes it.
3. Do that group in its own commits, run the suite, and deploy before the
   next group.

**A fix chosen once can be ported.** When the same code sits on many sites
(usually because it came from the new-DH-user-site template), the fix Rob
chose on the first site is written up as a step of its own, placed before
this one, with `whose_move: agent` and a check for exactly that code. That
mini-step is then done on the other sites without Rob, and this step stays
his for whatever is left.
