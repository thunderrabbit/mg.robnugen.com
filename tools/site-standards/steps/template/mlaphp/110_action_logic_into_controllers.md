---
title: Page scripts become controllers
position: 110
status: idea
whose_move: agent
check: mlaphp_controllers
---
Chapter 11. What is left of each page script after chapters 8 to 10 (reading
the request, calling a transaction, choosing a view) moves into a controller
class with an `__invoke()` method. The page script shrinks to building that
controller and calling it.

No site has done this yet, so there is no check or how-to.
