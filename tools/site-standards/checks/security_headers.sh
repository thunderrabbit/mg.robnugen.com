#!/usr/bin/env bash
# memo: commit
# security_headers REPO: every page sends the same security headers.
#
# Done when tracked PHP or .htaccess names each of X-Content-Type-Options,
# frame-ancestors, Referrer-Policy and Strict-Transport-Security. The check
# reads names only: the step file says which values, and where to send them.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

missing=''
for name in X-Content-Type-Options frame-ancestors Referrer-Policy Strict-Transport-Security; do
  [ -n "$(php_hits "$name" '*.php' '*.htaccess')" ] || missing="${missing:+$missing, }$name"
done
[ -z "$missing" ] || not_yet "nothing sends: $missing"
pass "the four security headers are sent"
