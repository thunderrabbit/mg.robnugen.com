---
title: One front controller
status: idea
whose_move: agent
check: mlaphp_front_controller
---
Chapter 14. URLs stop being file paths. Every request goes to one
`wwwroot/index.php`, which a router sends to the right page script (later, the
right controller). Old URLs keep working through the router.

No site has done this yet, so there is no check or how-to.
