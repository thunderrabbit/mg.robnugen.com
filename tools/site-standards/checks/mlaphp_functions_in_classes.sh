#!/usr/bin/env bash
# memo: commit
# mlaphp_functions_in_classes REPO: no loose functions (MLAPHP chapter 4).
#
# Done when no tracked PHP file outside classes/, Tests/, vendor/ and
# templates/ defines a named function at the start of a line. Closures
# (`function (`) and methods with a visibility keyword are not loose
# functions and are not counted.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

pattern='^[[:space:]]*function[[:space:]]+&?[A-Za-z_][A-Za-z0-9_]*[[:space:]]*\('
hits="$(git -C "$REPO" ls-files -z -- '*.php' ':!:classes/**' ':!:Tests/**' ':!:vendor/**' ':!:templates/**' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHn "$pattern") || true)"
[ -z "$hits" ] || {
  n="$(printf '%s\n' "$hits" | wc -l)"
  files="$(printf '%s\n' "$hits" | cut -d: -f1 | sort -u | wc -l)"
  not_yet "$n loose function(s) in $files file(s), first $(printf '%s\n' "$hits" | head -1 | cut -d: -f1,2)"
}
pass "no function is defined outside a class"
