#!/usr/bin/env bash
# memo: commit
# security_errors_hidden REPO: PHP's errors go to the log, not to the visitor.
#
# Done when tracked PHP turns display_errors off, the only file that turns it
# on is one that also turns it off (the switch a site's Config flips), and an
# exception handler is set, so an uncaught exception prints no stack trace.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

q="['\"]?"
setting="ini_set[[:space:]]*\\([[:space:]]*['\"]display(_startup)?_errors['\"][[:space:]]*,[[:space:]]*"
off="$(php_hits "${setting}${q}(0|[Oo]ff|false|FALSE)${q}[[:space:]]*\\)")"
on="$(php_hits "${setting}${q}(1|[Oo]n|true|TRUE|stdout)${q}[[:space:]]*\\)")"

if [ -n "$on" ]; then
  off_files="$(files_of "$off")"
  loud="$(printf '%s\n' "$on" | while IFS= read -r hit; do
    printf '%s\n' "$off_files" | grep -qxF "${hit%%:*}" || printf '%s\n' "$hit"
  done)"
  [ -z "$loud" ] || not_yet "$(first_hit "$loud") turns display_errors on for every visitor"
fi
[ -n "$off" ] || not_yet "nothing turns display_errors off, so the host's php.ini decides what a visitor sees"

handler="$(php_hits 'set_exception_handler[[:space:]]*\(')"
[ -n "$handler" ] || not_yet "no exception handler is set, so an uncaught exception is PHP's to print"
pass "$(first_hit "$off") hides errors and $(first_hit "$handler") catches what is thrown"
