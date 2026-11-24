---
title: A path containment check ends at a directory boundary
status: proven
whose_move: agent
check: security_path_boundary
model: sonnet
---
`str_starts_with($realTarget, $realBase)` is meant to ask "is this file inside
that directory?". Without a separator after the base it also says yes to a
sibling whose name merely starts the same way: `db_schemas_old` starts with
`db_schemas`. On the template this was the migration path check; the shape
of the filename already kept it from being reached, so this closes a gap
before anything depends on it.

The check reads tracked PHP for `str_starts_with()`, `strpos()` or
`strncmp()` given two variables named `$real...` and nothing after the base.
A site with no such comparison passes.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its commit
`End the containment check at a directory boundary` (f4d0e1d). One commit.

1. Compare against `$realBase . DIRECTORY_SEPARATOR`.
2. Add a unit test with a sibling directory whose name starts with the
   base's name, and see it refused.
3. Run the site's gates, then this step's check.
