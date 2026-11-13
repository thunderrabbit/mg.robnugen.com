---
title: Remove globals from prepend.php
status: idea
whose_move: agent
check: mlaphp_no_prepend_globals
---
From *Modernizing Legacy Applications in PHP*: page scripts stop reaching for
variables that `prepend.php` leaves in the global scope, and are handed what
they need instead. No site has done this yet, so there is no how-to and no
check.
