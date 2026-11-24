#!/usr/bin/env bash
# memo: commit
# security_sensitive_parameters REPO: no stack trace carries a password.
#
# Done when every typed password parameter in tracked PHP (`string $password`,
# `?string $new_password`, ...) has #[\SensitiveParameter] on its line. A name
# with "hash" in it is the stored hash, not the secret, and is not counted;
# nor is a property declaration. The raw login cookie deserves the attribute
# too, but its parameter has no one name to look for, so the check reads
# passwords only.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

param='\??string[[:space:]]+\$[A-Za-z0-9_]*[Pp]ass(word|wd)[A-Za-z0-9_]*'
hits="$(php_hits "$param")"
[ -n "$hits" ] || pass "no password parameters"

bare="$(printf '%s\n' "$hits" | awk '
  BEGIN { param = "[?]?string[[:space:]]+[$][A-Za-z0-9_]*[Pp]ass(word|wd)[A-Za-z0-9_]*" }
  /;[[:space:]]*$/ && !/\(/ { next }
  {
    rest = $0
    want = 0
    while (match(rest, param)) {
      if (substr(rest, RSTART, RLENGTH) !~ /[Hh]ash/) want++
      rest = substr(rest, RSTART + RLENGTH)
    }
    line = $0
    if (want > gsub(/SensitiveParameter/, "", line)) print
  }')"
[ -z "$bare" ] || {
  n="$(printf '%s\n' "$bare" | wc -l)"
  not_yet "$n password parameter line(s) lack #[\\SensitiveParameter], first $(first_hit "$bare")"
}
pass "every password parameter is marked #[\\SensitiveParameter]"
