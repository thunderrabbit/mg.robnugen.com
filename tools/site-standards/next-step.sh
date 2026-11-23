#!/usr/bin/env bash
# Look up this checkout's first open step on mg's standards page, for the
# /next-mg-step skill. Run it from inside a site's checkout.
#
#   tools/site-standards/next-step.sh                       which step, and who does it
#   tools/site-standards/next-step.sh --shortcode=example   when a checkout holds several sites
#   tools/site-standards/next-step.sh --leave-upgrade       Rob said no to setting upgrade_branch
#   tools/site-standards/next-step.sh --confirm --base=<commit>   after the work: facts, and "clean"
#
# Prints one JSON object and exits 0 whenever it could decide. Never writes
# to any repo and never changes anything on mg. The logic is in next_step.py.
set -euo pipefail
exec python3 -I -B "$(dirname "${BASH_SOURCE[0]}")/next_step.py" "$@"
