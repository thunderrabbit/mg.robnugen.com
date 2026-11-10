#!/usr/bin/env bash
# tooling_on_php REPO X: the repo's checks run on PHP X (for example 8.5): the
# tracked pre-commit hook's image, a composer.json PHP requirement that allows
# X, and the runner image standards-mcp picks for it.
#
# "Allows", not "names": a site still served an older PHP keeps its floor
# there (">=8.1" while moving to 8.3), or Composer refuses to install on the
# server. No memo: core.hooksPath lives in .git/config, which is not in the
# commit, and the check is cheap enough to run every scan.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
X="${2:-}"
[[ "$X" =~ ^[0-9]+\.[0-9]+$ ]] || unknown "this check needs a PHP version such as 8.5"

need_hooks
hook="${HOOKS#"$REPO"/}/pre-commit"
[ -f "$REPO/$hook" ] || not_yet "no $hook"
grep -Eq "runner:php${X//./\\.}([^0-9.]|\$)" "$REPO/$hook" \
  || not_yet "the pre-commit hook does not run on the php$X image"

[ -f "$REPO/composer.json" ] || not_yet "no composer.json"
wanted="$(python3 -I -c '
import json, sys
try:
    data = json.load(open(sys.argv[1]))
except Exception:
    sys.exit(3)
require = data.get("require", {}) if isinstance(data, dict) else {}
print(require.get("php", "") if isinstance(require, dict) else "")
' "$REPO/composer.json")" || unknown "composer.json could not be read"
[ -n "$wanted" ] || not_yet "composer.json does not say which PHP it needs"
rc=0
python3 -I "$HERE/composer_php_allows.py" "$wanted" "$X" || rc=$?
case "$rc" in
  0) ;;
  1) not_yet "composer.json asks for PHP $wanted, which does not allow $X" ;;
  *) unknown "composer.json asks for PHP $wanted, which this check cannot read" ;;
esac

[ -f "$STANDARDS_MCP/runner-image.sh" ] || unknown "standards-mcp is not at $STANDARDS_MCP"
. "$STANDARDS_MCP/runner-image.sh"
image="$(runner_image "$REPO")"
[ "${image##*:}" = "php$X" ] || not_yet "standards-mcp runs this repo on ${image##*:}"
pass "hook, composer.json and standards-mcp all allow PHP $X"
