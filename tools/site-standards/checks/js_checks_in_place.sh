#!/usr/bin/env bash
# js_checks_in_place REPO: a site that runs any JavaScript has a smoke test
# (js-smoke.sh at the repo root, run by standards-mcp's run-webdriver.sh),
# an ESLint config at the root, and a switched-on pre-commit hook that runs
# ESLint. A site with no scripts at all passes without them.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

count="$(python3 -I -B - "$HERE" "$REPO" <<'PY'
import sys
sys.path.insert(0, sys.argv[1])
import js_scripts as j
try:
    found = j.read(sys.argv[2])
    own = sum(1 for p in j.tracked_files(sys.argv[2]) if p.endswith((".js", ".mjs")))
    print(len(found["scripts"]) + len(found["importmap"]) + len(found["inline"]) + own)
except Exception as e:  # noqa: BLE001
    print(f"x {e}")
PY
)"
[[ "$count" =~ ^[0-9]+$ ]] || unknown "could not read the site's scripts: ${count#x }"
[ "$count" -gt 0 ] || pass "the site runs no JavaScript"

[ -x "$REPO/js-smoke.sh" ] || not_yet "no executable js-smoke.sh at the repo root"
config=""
for name in eslint.config.js eslint.config.mjs eslint.config.cjs; do
  [ -f "$REPO/$name" ] && config="$name"
done
[ -n "$config" ] || not_yet "no eslint.config.js (or .mjs/.cjs) at the repo root"
[ -f "$REPO/.githooks/pre-commit" ] || not_yet "no .githooks/pre-commit"
grep -q eslint "$REPO/.githooks/pre-commit" || not_yet "the pre-commit hook does not run ESLint"
[ "$(git -C "$REPO" config core.hooksPath 2>/dev/null)" = ".githooks" ] \
  || not_yet "the pre-commit hook is not switched on (git config core.hooksPath .githooks)"
pass "js-smoke.sh, $config and the ESLint pre-commit hook are set up"
