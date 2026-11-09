#!/usr/bin/env bash
# mlaphp_print_rob_in_class REPO: print_rob() is \PrintRob\Debug::printRob()
# (MLAPHP chapter 4, the template's one loose function, ported).
#
# Done when no tracked PHP file outside vendor/ says `print_rob(`, whether
# defining it, calling it or in a commented-out call (a docblock line, one
# starting with `*`, may still tell where the function came from), and
# classes/PrintRob/Debug.php defines printRob(). A site that never had
# print_rob() passes too: there is nothing to move. print_roblog() and
# print_robs() are other functions and are not counted.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

class='classes/PrintRob/Debug.php'
pattern='(^|[^A-Za-z0-9_$>:\\])print_rob[[:space:]]*\('
hits="$(git -C "$REPO" ls-files -z -- '*.php' ':!:vendor/**' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHn "$pattern") \
  | grep -Ev '^[^:]+:[0-9]+:[[:space:]]*\*' || true)"
[ -z "$hits" ] || {
  n="$(printf '%s\n' "$hits" | wc -l)"
  files="$(printf '%s\n' "$hits" | cut -d: -f1 | sort -u | wc -l)"
  not_yet "print_rob( is still named $n time(s) in $files file(s), first $(printf '%s\n' "$hits" | head -1 | cut -d: -f1,2)"
}
if git -C "$REPO" ls-files --error-unmatch -- "$class" >/dev/null 2>&1; then
  grep -Eq 'public[[:space:]]+static[[:space:]]+function[[:space:]]+printRob[[:space:]]*\(' "$REPO/$class" \
    || not_yet "$class does not define printRob()"
  pass "print_rob() is \\PrintRob\\Debug::printRob()"
fi
pass "no print_rob() to move"
