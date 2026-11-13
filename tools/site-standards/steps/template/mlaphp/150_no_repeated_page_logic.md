---
title: No repeated logic in page scripts
status: idea
whose_move: agent
check: mlaphp_no_repeated_logic
---
Chapter 15. What every page script repeats (the login check, the paid-account
check, the JSON error reply, the two-step layout render) moves to one place:
the front controller, a guard class, or a response helper. Each page script
keeps only what is its own.

A check would count the known repeated blocks. No site has done this yet.
