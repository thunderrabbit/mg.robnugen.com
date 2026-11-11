#!/usr/bin/env bash
# memo: commit
# mlaphp_unused_function_removed REPO NAME: a loose debug function nothing
# calls is gone (MLAPHP chapter 4: it is removed, not moved).
#
# NAME is one of the functions Rob has said to remove where unused:
# print_robs, log_rob, print_cookie_to_file, print_rob_to_file. Done when no
# tracked PHP file outside vendor/ says `NAME(`, whether defining it, calling
# it or in a commented-out call (a docblock line, one starting with `*`, is
# not counted). A site that never had the function passes: there is nothing
# to remove. The check cannot tell a live call from a commented-out one; the
# step's how-to has the agent stop if a live one exists.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

case "${2:-}" in
  print_robs|log_rob|print_cookie_to_file|print_rob_to_file) name="$2" ;;
  *) unknown "the step's param must name one of the four functions" ;;
esac

pattern="(^|[^A-Za-z0-9_\$>:\\\\])${name}[[:space:]]*\\("
hits="$(git -C "$REPO" ls-files -z -- '*.php' ':!:vendor/**' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHn "$pattern") \
  | grep -Ev '^[^:]+:[0-9]+:[[:space:]]*\*' || true)"
[ -z "$hits" ] || {
  n="$(printf '%s\n' "$hits" | wc -l)"
  files="$(printf '%s\n' "$hits" | cut -d: -f1 | sort -u | wc -l)"
  not_yet "${name}( is still named $n time(s) in $files file(s), first $(printf '%s\n' "$hits" | head -1 | cut -d: -f1,2)"
}
pass "no ${name}() here"
