---
title: SQL lives in gateway classes
position: 80
status: proven
whose_move: rob
check: mlaphp_sql_in_gateways
---
Chapter 8. Page scripts do not hold SQL. Each query moves into a method on a
gateway class for its table (or group of tables), and the page script calls
that method. The SQL keeps its behaviour exactly; this step is a move, not a
rewrite.

The check counts `->prepare(`, `->query(`, `->exec(` and `mysqli_query(`
calls in PHP files under `wwwroot/`. A site with no `wwwroot/` does the
public-files step first.

## How to do it

This step is Rob's: how code is grouped and named is a matter of taste, so
an agent never does it alone. With Rob, one group at a time:

1. The tests step comes first: moving a query without a test that runs it
   is how a typo reaches production. List the calls:
   `tools/site-standards/checks/mlaphp_sql_in_gateways.sh <repo>` names the
   first; `git ls-files -z 'wwwroot/**/*.php' | xargs -0 grep -nE -e
   '->(prepare|query|exec)\s*\(|mysqli_query\s*\('` lists them all.
2. The agent proposes a shape for one group: the gateway class for its table,
   its method names, and what each returns, with the SQL copied exactly. Rob
   picks or changes it.
3. Do that group in its own commits, run the suite, and deploy before the
   next group.

**A fix chosen once can be ported.** When the same code sits on many sites
(usually because it came from the new-DH-user-site template), the fix Rob
chose on the first site is written up as a step of its own, placed before
this one, with `whose_move: agent` and a check for exactly that code. That
mini-step is then done on the other sites without Rob, and this step stays
his for whatever is left.
