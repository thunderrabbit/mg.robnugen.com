#!/usr/bin/env bash
# memo: commit
# security_logout_post_only REPO: only a POST logs anyone out.
#
# Done when every tracked logout page (logout.php or logout/index.php outside
# classes/ and templates/) asks for the request method and names POST, and no
# tracked PHP still links to /logout with an href. A site with no logout page
# passes.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

pages="$(git -C "$REPO" ls-files 2>/dev/null \
  | grep -E '(^|/)logout(/index)?\.php$' \
  | grep -Ev '^(vendor|Tests|tests|templates|classes)/' || true)"
[ -n "$pages" ] || pass "no logout page"

while IFS= read -r page; do
  { grep -q 'REQUEST_METHOD' "$REPO/$page" && grep -q 'POST' "$REPO/$page"; } \
    || not_yet "$page logs out on any request: a link or an <img> on another site signs the visitor out"
done <<< "$pages"

links="$(php_hits "href=['\"]/logout")"
[ -z "$links" ] || not_yet "$(first_hit "$links") still links to /logout: make it a form that POSTs the CSRF token"
pass "$(printf '%s' "$pages" | head -1) logs out on POST only, and nothing links to it"
