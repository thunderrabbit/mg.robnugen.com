---
title: SQL lives in gateway classes
position: 80
status: idea
whose_move: agent
check: mlaphp_sql_in_gateways
---
Chapter 8. Page scripts do not hold SQL. Each query moves into a method on a
gateway class for its table (or group of tables), and the page script calls
that method. The SQL keeps its behaviour exactly; this step is a move, not a
rewrite.

The check would count page scripts under `wwwroot/` that call `prepare(`,
`query(` or `exec(`. No site has done this yet, so there is no how-to.
