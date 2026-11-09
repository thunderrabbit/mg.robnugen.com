---
title: Functions are moved into classes
position: 40
status: idea
whose_move: agent
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

1. List them: `tools/site-standards/checks/mlaphp_functions_in_classes.sh
   <repo>` names the first; `git ls-files -z '*.php' ':!:classes/**'
   ':!:Tests/**' ':!:vendor/**' ':!:templates/**' | xargs -0 grep -nE
   '^\s*function\s+\w+\s*\('` lists them all.
2. Group them by what they are about, and make one class per group under
   `classes/`, named for the group (`Debug`, `Format`), with each function as
   a `public static` method of the same name.
3. Leave the old function in place for one commit, its body a one-line call
   to the new method, so nothing breaks while the callers move.
4. Change every caller to the static call (`Debug::printRob($x)`), then delete
   the old function. Run the suite after each group.
5. The template's `prepend.php` debug helpers (`print_rob`, `print_roblog`)
   are on every site built from it: move them once in new-DH-user-site and
   copy that change to each site.
