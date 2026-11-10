---
title: The site's safe suite passes on PHP {target}
position: 6
status: idea
whose_move: agent
check: suite_passes_on_php
param: target
---
The site's own safe suite passes against its test domain while that domain serves
the site's target PHP. "Safe" matters: a site whose test backend is its live backend must never
have the suite create real records, so only tests that cannot are in the suite.
mg does not know how a site tests; the repo's `safe-suite.sh` does.

The scanner runs it only when it must: the test domain is on the target, the code
compiles on both PHPs, and the test domain runs the checkout's commit. A site with
no test domain skips this step.

## How to do it

1. Make sure the repo has a root-level `safe-suite.sh` that runs only the tests
   that are safe against the test domain and exits 0 or 1. It receives the domain
   as `SITE_TEST_DOMAIN`, and may write only under `/tmp`.
2. Run it the way you always have, through the Docker harness:
   `SITE_TEST_DOMAIN=<test domain> ~/work/rob/standards-mcp/run-site-suite.sh <repo> <target>`.
   Fix what it reports.
3. Deploy the fixes to the test domain, so it runs the commit being tested.
4. A suite only sees responses, so read the test domain's PHP error log as
   well: the target's deprecation notices land there, not in the suite.
5. The next scan runs the suite itself and ticks this step.
