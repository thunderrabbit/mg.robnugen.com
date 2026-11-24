#!/usr/bin/env bash
# memo: commit
# security_login_counts_first REPO: a login attempt is counted before its
# password is checked.
#
# Done when the class that reads login_attempts has an admit() method, the
# login path calls it, and nothing outside the tests still calls
# isThrottled(), the read-only question a parallel burst slips past.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

mapfile -t throttles < <(files_of "$(php_hits 'login_attempts')" | grep -v '^$')
[ "${#throttles[@]}" -gt 0 ] || not_yet "no login throttle: no tracked PHP names login_attempts"

admit="$(php_hits 'function[[:space:]]+admit[[:space:]]*\(' "${throttles[@]}")"
[ -n "$admit" ] \
  || not_yet "the login throttle has no admit(): it checks, then records, and a parallel burst gets in between"
called="$(php_hits '->admit[[:space:]]*\(')"
[ -n "$called" ] || not_yet "$(first_hit "$admit") has admit(), but the login path does not call it"
old="$(php_hits '->isThrottled[[:space:]]*\(')"
[ -z "$old" ] || not_yet "$(first_hit "$old") still asks isThrottled(), which counts nothing"
pass "$(first_hit "$called") counts each attempt before its password is checked"
