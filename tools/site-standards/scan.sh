#!/usr/bin/env bash
# Scan each site's checkout on Lemur 13 and push the results to mg, for the
# standards page (/admin/dh/standards.php).
#
#   tools/site-standards/scan.sh --dry-run          every site; show what an import would do
#   tools/site-standards/scan.sh                    every site, for real
#   tools/site-standards/scan.sh example-games      only the sites named
#
# Reads the step files from the commit the mg server is running (Rob approves
# a step by deploying it), whatever branch this checkout is on. Checks a site
# only when its checkout is clean and at the commit its server runs (asked
# over ssh); any other site is skipped and keeps the results it had. Never
# writes to a repo it checks, here or on a server. The logic is in scan.py.
#
# STEPS_REF=<ref> reads the step files from a ref instead. It says so on
# stderr and is for a look at steps before they are deployed, not routine use.
set -euo pipefail
exec python3 -I "$(dirname "${BASH_SOURCE[0]}")/scan.py" "$@"
