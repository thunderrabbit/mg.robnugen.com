#!/usr/bin/env bash
# mlaphp_no_includes_in_classes REPO: no class pulls in a code file by name
# (MLAPHP chapter 12).
#
# Done when no tracked PHP file under classes/ has an include or require whose
# path is written out: a string literal, or one built from __DIR__ or
# dirname(). A path held in a variable is allowed; that is what an autoloader
# and a view renderer do.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[ -d "$REPO/classes" ] || not_yet "no classes/ directory"

literal='\b(include|require)(_once)?\b[[:space:]]*\(?[[:space:]]*(["'"'"']|__DIR__|dirname[[:space:]]*\()'
hits="$(git -C "$REPO" ls-files -z -- 'classes/*.php' 'classes/**/*.php' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHn "$literal" | grep -Ev "^[^:]*:[0-9]+:[[:space:]]*(//|#|/?\\*)") || true)"
[ -z "$hits" ] || {
  n="$(printf '%s\n' "$hits" | wc -l)"
  first="$(printf '%s\n' "$hits" | head -1 | cut -d: -f1,2)"
  not_yet "$n include(s) by name under classes/, first $first"
}
pass "no class includes a code file by name"
