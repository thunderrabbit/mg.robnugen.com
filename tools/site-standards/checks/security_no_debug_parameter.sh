#!/usr/bin/env bash
# memo: commit
# security_no_debug_parameter REPO: no page changes what it prints because a
# visitor added ?debug to the URL.
#
# Not yet while tracked PHP reads a request parameter named debug
# ($_GET['debug'], $_REQUEST['debug'] or a request object's ->get['debug']).
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

q="['\"]"
hits="$(php_hits "(_GET|_REQUEST|->get)[[:space:]]*\\[[[:space:]]*${q}debug${q}")"
[ -z "$hits" ] || not_yet "$(first_hit "$hits") reads ?debug from the URL, for any visitor"
pass "no page reads a debug parameter from the URL"
