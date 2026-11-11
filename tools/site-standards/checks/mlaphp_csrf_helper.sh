#!/usr/bin/env bash
# memo: commit
# mlaphp_csrf_helper REPO field|token: the loose csrf_field() or csrf_token()
# is the template's own $csrf (MLAPHP chapter 4, ported).
#
# Done when no tracked PHP file outside vendor/, templates included, says
# `csrf_field(` (or `csrf_token(`): defining it, calling it, in a commented-out
# call, or as function_exists('csrf_field'). A docblock line, one starting
# with `*`, may still tell where the function came from. `$csrf_field` and
# `$_SESSION['csrf_token']` are variables, not the function, and are not
# counted.
#
# Where the Template class takes the CSRFProtectaroo, no page may set a
# template variable named `csrf`: the template's own $csrf would silently
# replace it (a test under Tests/ may, to prove exactly that). A site that
# never had the helpers passes: there is nothing to move.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

case "${2:-}" in
  field) name='csrf_field'; method='field' ;;
  token) name='csrf_token'; method='getToken' ;;
  *) unknown "the step's param must be field or token" ;;
esac

files() { git -C "$REPO" ls-files -z -- '*.php' ':!:vendor/**' 2>/dev/null; }
first() { printf '%s\n' "$1" | head -1 | cut -d: -f1,2; }

q="['\"]"
pattern="(^|[^A-Za-z0-9_\$>:\\\\])${name}[[:space:]]*\\(|function_exists[[:space:]]*\\([[:space:]]*${q}${name}${q}"
hits="$(files | (cd "$REPO" && xargs -0 -r grep -EHn "$pattern") \
  | grep -Ev '^[^:]+:[0-9]+:[[:space:]]*\*' || true)"
[ -z "$hits" ] || {
  n="$(printf '%s\n' "$hits" | wc -l)"
  count="$(printf '%s\n' "$hits" | cut -d: -f1 | sort -u | wc -l)"
  not_yet "${name}( is still named $n time(s) in $count file(s), first $(first "$hits")"
}

template=''
for candidate in classes/View/Template.php classes/Template.php; do
  if git -C "$REPO" ls-files --error-unmatch -- "$candidate" >/dev/null 2>&1 \
    && grep -q 'CSRFProtectaroo' "$REPO/$candidate"; then
    template="$candidate"
    break
  fi
done
[ -n "$template" ] || pass "no ${name}() to move"

shadow="$(git -C "$REPO" ls-files -z -- '*.php' ':!:vendor/**' ':!:Tests/**' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHn -e "->set\\([[:space:]]*${q}csrf${q}") || true)"
[ -z "$shadow" ] \
  || not_yet "$(first "$shadow") sets a template variable named csrf, which the template's own \$csrf replaces"

pass "${name}() is the template's \$csrf->${method}()"
