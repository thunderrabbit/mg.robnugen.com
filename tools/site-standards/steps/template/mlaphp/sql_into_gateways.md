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

The check counts `->prepare(`, `->query(`, `->exec(` and `mysqli_query(`
calls in PHP files under `wwwroot/`. A site with no `wwwroot/` does the
public-files step first.

## How to do it

1. Do the tests step first: moving a query without a test that runs it is
   how a typo reaches production.
2. List the calls: `tools/site-standards/checks/mlaphp_sql_in_gateways.sh
   <repo>` names the first; `git ls-files -z 'wwwroot/**/*.php' | xargs -0
   grep -nE -e '->(prepare|query|exec)\s*\(|mysqli_query\s*\('` lists them
   all.
3. For each table, make a gateway class under `classes/` (`UsersGateway`)
   that takes the database connection in its constructor. Move each query into
   a method named for what it returns or does (`insertUser`,
   `findByUsername`), with the SQL copied exactly and its values as
   parameters.
4. In the page script, build the gateway and call the method. Change nothing
   else in the same commit.
5. The template's `wwwroot/login/register.php` holds one INSERT on every site
   built from it: move it once in new-DH-user-site and copy that change to
   each site.
