#!/usr/bin/env bash
# mlaphp_no_global REPO: no global keyword (MLAPHP chapter 5).
#
# Done when no tracked PHP file outside vendor/ uses `global $x` or $GLOBALS.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

pattern='^[[:space:]]*global[[:space:]]+\$|\$GLOBALS\b'
hits="$(git -C "$REPO" ls-files -z -- '*.php' ':!:vendor/**' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHn "$pattern" | grep -Ev "^[^:]*:[0-9]+:[[:space:]]*(//|#|/?\\*)") || true)"
[ -z "$hits" ] || {
  n="$(printf '%s\n' "$hits" | wc -l)"
  not_yet "$n use(s) of global or \$GLOBALS, first $(printf '%s\n' "$hits" | head -1 | cut -d: -f1,2)"
}
pass "no global keyword and no \$GLOBALS"
