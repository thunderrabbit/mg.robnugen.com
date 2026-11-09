#!/usr/bin/env bash
# memo: commit
# mlaphp_sql_in_gateways REPO: page scripts hold no SQL (MLAPHP chapter 8).
#
# Done when no tracked PHP file under wwwroot/ runs a query itself: no
# ->prepare(, ->query( or ->exec( call and no mysqli_query(. A page script
# calls a gateway method instead, and the gateway under classes/ holds the SQL.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[ -d "$REPO/wwwroot" ] || not_yet "no wwwroot/ directory; the public-files step comes first"

pattern='->(prepare|query|exec)[[:space:]]*\(|\bmysqli_query[[:space:]]*\('
hits="$(git -C "$REPO" ls-files -z -- 'wwwroot/*.php' 'wwwroot/**/*.php' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHn -e "$pattern" | grep -Ev "^[^:]*:[0-9]+:[[:space:]]*(//|#|/?\\*)") || true)"
[ -z "$hits" ] || {
  n="$(printf '%s\n' "$hits" | wc -l)"
  files="$(printf '%s\n' "$hits" | cut -d: -f1 | sort -u | wc -l)"
  not_yet "$n query call(s) in $files page script(s), first $(printf '%s\n' "$hits" | head -1 | cut -d: -f1,2)"
}
pass "no page script runs a query itself"
