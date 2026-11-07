#!/usr/bin/env bash
# js_checks_in_place REPO: a site that runs any JavaScript has a smoke test
# (a dated testing-js-since-<yyyy-mmm-dd>.sh or .js at the repo root, or a
# "JS tests:" line in JS_LIBRARIES.md naming an existing suite), an ESLint
# config at the root, and a switched-on pre-commit hook that runs ESLint. A
# site with no scripts at all passes without them. The names are described
# in ../NAMES.md; the hooks directory is wherever core.hooksPath points (see
# need_hooks in ../lib.sh).
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

found="$(python3 -I -B "$HERE/js_scripts.py" smoke "$REPO")"
kind="${found%%$'\t'*}"
value="${found#*$'\t'}"
case "$kind" in
  strict|sh|js|plain) ;;
  none) not_yet "no smoke test: write testing-js-since-<yyyy-mmm-dd>.sh, or name the site's existing suite in JS_LIBRARIES.md as \"JS tests: <command>\"" ;;
  bad) not_yet "$value" ;;
  *) unknown "could not look for the smoke test: $value" ;;
esac
config=""
for name in eslint.config.js eslint.config.mjs eslint.config.cjs; do
  [ -f "$REPO/$name" ] && config="$name"
done
[ -n "$config" ] || not_yet "no eslint.config.js (or .mjs/.cjs) at the repo root"
need_hooks
[ -f "$HOOKS/pre-commit" ] || not_yet "no pre-commit hook in ${HOOKS#"$REPO/"}/"
grep -q eslint "$HOOKS/pre-commit" || not_yet "the pre-commit hook does not run ESLint"
case "$kind" in
  sh|js) what="$value" ;;
  *) what="the suite named in JS_LIBRARIES.md" ;;
esac
pass "$what, $config and the ESLint pre-commit hook are set up"
