#!/usr/bin/env bash
# memo: commit
# admin_link_admins_only REPO: the home page shows its /admin/ link to
# admins only.
#
# Done when templates/index.tpl.php has no link to /admin/, or every such link
# sits inside an `if` whose condition names is_admin, and wwwroot/index.php
# sets "is_admin" for the page. A site without that template passes: there is
# nothing to hide.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

tpl='templates/index.tpl.php'
git -C "$REPO" ls-files --error-unmatch -- "$tpl" >/dev/null 2>&1 \
  || pass "no $tpl, so no Admin Dashboard link to hide"
grep -Eq 'href=["'\'']/admin/?["'\'']' "$REPO/$tpl" \
  || pass "$tpl has no link to /admin/"

# Walk the template: keep a stack of open `if`s (true = names is_admin) and
# report the first /admin/ link with none of them true.
open="$(awk '
  /<\?(php)?[[:space:]]*(if|elseif)[[:space:]]*\(.*\)[[:space:]]*:/ {
    stack[++n] = ($0 ~ /is_admin/) ? 1 : 0
  }
  /endif/ { if (n > 0) n-- }
  /href=["\047]\/admin\/?["\047]/ {
    ok = 0
    for (i = 1; i <= n; i++) if (stack[i]) ok = 1
    if (!ok) { print NR; exit }
  }
' "$REPO/$tpl")"
[ -z "$open" ] || not_yet "$tpl line $open links to /admin/ outside an is_admin conditional"

grep -Eq '["'\'']is_admin["'\'']' "$REPO/wwwroot/index.php" 2>/dev/null \
  || not_yet "wwwroot/index.php never sets is_admin for the home page"
pass "the /admin/ link in $tpl is for admins only"
