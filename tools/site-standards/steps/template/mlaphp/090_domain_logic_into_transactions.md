---
title: Domain logic lives in transaction classes
status: idea
whose_move: agent
check: mlaphp_transactions
---
Chapter 9. Business rules (validation, calculations, multi-step writes) leave
the page script for a transaction class, one per thing the user does
(`CompleteTodo`, `CreateAgent`). The page script collects input, calls the
transaction, and hands the result on. Steps that must happen in a set order,
or together, get a database transaction around them here.

There is no clean way for a script to see domain logic; a check would have to
approximate it. No site has done this yet.
