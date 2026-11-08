#!/usr/bin/env bash
# mlaphp_public_split REPO: public files are separate from the rest (MLAPHP
# chapter 13).
#
# Done when wwwroot/ exists and nothing tracked in it is meant to stay off the
# web: classes, templates, SQL, Config.php, .env, composer files, vendor/.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[ -d "$REPO/wwwroot" ] || not_yet "no wwwroot/: the public files are not separated from the rest"

private="$(git -C "$REPO" ls-files -- wwwroot 2>/dev/null | grep -E \
  '(^|/)(classes|vendor|templates)/|\.tpl\.php$|\.sql$|(^|/)Config\.php$|(^|/)\.env|(^|/)composer\.(json|lock)$' || true)"
[ -z "$private" ] || {
  n="$(printf '%s\n' "$private" | wc -l)"
  not_yet "$n non-public file(s) inside wwwroot/, first $(printf '%s\n' "$private" | head -1)"
}
pass "wwwroot/ holds only what the web needs"
