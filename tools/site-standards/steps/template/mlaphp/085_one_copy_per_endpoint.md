---
title: One copy of each endpoint
status: idea
whose_move: agent
check: mlaphp_one_copy_per_endpoint
---
Not a chapter of the book, but needed before chapter 9 on a site that grew a
second API beside its first. When two endpoints do the same job (an old
`api/` script and its versioned replacement, or a web form and an API call
that create the same record) their rules drift apart. Extracting domain logic
then means choosing which copy is right, so choose first: point every caller
at one copy and delete the other.

How a check would find duplicates is undecided; the likely measure is a list
in the repo of retired endpoints, each with no caller left. No site has done
this yet.
