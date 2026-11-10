#!/usr/bin/env bash
# memo: commit
# mlaphp_print_roblog_in_class REPO: print_roblog() is
# \PrintRob\Debug::printRobLog() (MLAPHP chapter 4, ported).
#
# Done when no tracked PHP file outside vendor/ says `print_roblog(`, whether
# defining it, calling it or in a commented-out call (a docblock line, one
# starting with `*`, may still tell where the function came from). The pass
# line names printRobLog() when classes/PrintRob/Debug.php defines it; a site
# that never had print_roblog() passes too: there is nothing to move.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

class='classes/PrintRob/Debug.php'
pattern='(^|[^A-Za-z0-9_$>:\\])print_roblog[[:space:]]*\('
hits="$(git -C "$REPO" ls-files -z -- '*.php' ':!:vendor/**' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHn "$pattern") \
  | grep -Ev '^[^:]+:[0-9]+:[[:space:]]*\*' || true)"
[ -z "$hits" ] || {
  n="$(printf '%s\n' "$hits" | wc -l)"
  files="$(printf '%s\n' "$hits" | cut -d: -f1 | sort -u | wc -l)"
  not_yet "print_roblog( is still named $n time(s) in $files file(s), first $(printf '%s\n' "$hits" | head -1 | cut -d: -f1,2)"
}
if git -C "$REPO" ls-files --error-unmatch -- "$class" >/dev/null 2>&1 \
  && grep -Eq 'public[[:space:]]+static[[:space:]]+function[[:space:]]+printRobLog[[:space:]]*\(' "$REPO/$class"; then
  pass "print_roblog() is \\PrintRob\\Debug::printRobLog()"
fi
pass "no print_roblog() to move"
