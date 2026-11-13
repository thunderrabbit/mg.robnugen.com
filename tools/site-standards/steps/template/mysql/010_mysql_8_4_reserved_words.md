---
title: Queries survive MySQL 8.4's reserved words
status: proven
whose_move: agent
check: mysql84_reserved_words
---
MySQL 8.4 reserves four words that 8.0 allowed as plain names: `MANUAL`,
`PARALLEL`, `QUALIFY` and `TABLESAMPLE`. A query that uses one of them as a
bare table or column name fails on 8.4. The data is safe; the page or
feature behind that query breaks.

The check reads the repo's `.sql` files. If no table or column has one of
those names, the step is done. If one does, every PHP line that mentions
the name must have it in backticks.

## How to do it

1. Find each query that uses the name.
2. Put backticks around it: `` SELECT `manual` FROM products ``. Don't use
   single or double quotes, and don't rename the table or column.
3. If the check names a PHP line that only mentions the word in English (a
   comment or a label), backtick it anyway or file the step's NAh issue.
