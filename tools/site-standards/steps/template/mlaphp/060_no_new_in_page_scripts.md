---
title: Objects are handed in, not made in place
status: idea
whose_move: agent
check: mlaphp_no_new_in_pages
---
Chapter 6. A class does not build the objects it works with (`new Database`
inside a method); it takes them as constructor arguments. Page scripts still
build objects at this stage: they are the place objects get made until a
container takes that over (chapter 16).

What a check should count here is undecided: `new` inside `classes/` methods
other than value objects and factories is the likely measure. No site has done
this yet.
