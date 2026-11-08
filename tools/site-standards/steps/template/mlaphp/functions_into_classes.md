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

The check would count function definitions outside `classes/`, `Tests/`,
`vendor/` and templates. No site has done this yet, so there is no how-to.
