#!/usr/bin/env bash
# tooling_on_php REPO X: the repo's checks run on PHP X (for example 8.5): the
# pre-commit hook's image, composer.json's php requirement, and the runner
# image standards-mcp picks for it.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"
X="${2:-}"
[[ "$X" =~ ^[0-9]+\.[0-9]+$ ]] || unknown "this check needs a PHP version such as 8.5"

[ -f "$REPO/.githooks/pre-commit" ] || not_yet "no .githooks/pre-commit"
grep -Eq "runner:php${X//./\\.}([^0-9.]|\$)" "$REPO/.githooks/pre-commit" \
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
case "$wanted" in
  *"$X"*) ;;
  "") not_yet "composer.json does not say which PHP it needs" ;;
  *) not_yet "composer.json asks for PHP $wanted" ;;
esac

[ -f "$STANDARDS_MCP/runner-image.sh" ] || unknown "standards-mcp is not at $STANDARDS_MCP"
. "$STANDARDS_MCP/runner-image.sh"
image="$(runner_image "$REPO")"
[ "${image##*:}" = "php$X" ] || not_yet "standards-mcp runs this repo on ${image##*:}"
pass "hook, composer.json and standards-mcp all say PHP $X"
