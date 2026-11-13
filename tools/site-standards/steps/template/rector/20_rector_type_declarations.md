---
title: Declare the types Rector can prove
status: proven
whose_move: agent
check: rector_types
---
`rector.php` also applies `SetList::TYPE_DECLARATION`, and a dry run finds
nothing left to change. Rector adds only the parameter, return and property
types it can prove from the code.

A declared type makes PHP throw where it used to coerce, so this step is not
only spelling. Each diff needs reading.

## How to do it

1. Add `SetList::TYPE_DECLARATION` to the sets in `rector.php`.
2. Apply one rule at a time with `--only=`, and commit each.
3. Before committing a parameter type, trace what the call sites really pass.
4. Leave a callback untyped when it reads data the site does not control, such
   as a request body or a third party's JSON: list that rule and file under
   `skip` in `rector.php`, with a comment saying why. Untyped, a malformed
   value falls through to the existing error handling; typed, it is an
   uncaught TypeError.
5. Run the Unit suite, phpcs and PHPStan after each rule.
