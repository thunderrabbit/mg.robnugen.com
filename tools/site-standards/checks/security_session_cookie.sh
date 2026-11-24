#!/usr/bin/env bash
# memo: commit
# security_session_cookie REPO: one class starts every PHP session, under a
# cookie name no sibling subdomain can plant.
#
# Done when every session_start() call in tracked PHP is in one file, and
# that file names the cookie with the __Host- prefix and sets cookie_secure.
# A site that never starts a session passes: there is no session to protect.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

calls="$(php_hits '(^|[^A-Za-z0-9_>:$])session_start[[:space:]]*\(')"
[ -n "$calls" ] || pass "no PHP session to protect"

files="$(files_of "$calls")"
n="$(printf '%s\n' "$files" | wc -l)"
[ "$n" -eq 1 ] \
  || not_yet "session_start() is called in $n files ($(printf '%s' "$files" | tr '\n' ' ')): one class should start every session"

grep -q '__Host-' "$REPO/$files" \
  || not_yet "$files starts the session under a name without the __Host- prefix, so a sibling site can plant its id"
grep -q 'cookie_secure' "$REPO/$files" \
  || not_yet "$files does not set cookie_secure, which a __Host- cookie needs"
pass "$files starts every session, under a __Host- name"
