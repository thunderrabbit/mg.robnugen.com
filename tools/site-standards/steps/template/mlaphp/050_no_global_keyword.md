---
title: No global keyword
status: proven
whose_move: agent
check: mlaphp_no_global
---
Chapter 5. No function or method reaches into the global scope with `global
$x` or `$GLOBALS['x']`. What it needs is passed in: as a constructor argument
for a class, or as a parameter for a function.

The check passes when no PHP file outside `vendor/` uses the `global` keyword
or `$GLOBALS`.

## How to do it

1. List them: `git ls-files -z '*.php' ':!:vendor/**' | xargs -0 grep -nE
   '^\s*global\s+\$|\$GLOBALS'`.
2. For a function or method, take what it read from the global scope as a
   parameter (a constructor argument, for a class), and change each caller to
   pass it.
3. The template's `csrf_field()` and `csrf_token()` in `prepend.php` read
   `global $csrfProtect`. They are two steps of their own, before this one
   (`csrf_field() comes from the template's $csrf` and its sibling), so by
   now they are gone.
4. A template that reads `global` gets those values set on it by its page
   script, like any other template variable.
5. Run the suite, then submit a form on the test domain: a missing token is a
   403, so a broken form shows at once.
