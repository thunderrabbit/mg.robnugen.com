---
title: A dependency injection container
position: 160
status: idea
whose_move: agent
check: mlaphp_di_container
---
Chapter 16. Building objects moves out of page scripts into one container,
which knows how to make each controller and everything it needs. A page
script, or the router, asks the container for its controller and invokes it.
After this the site is ready to move onto a framework, or to stay as it is.

No site has done this yet, so there is no check or how-to.
