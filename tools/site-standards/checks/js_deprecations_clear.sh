#!/usr/bin/env bash
# js_deprecations_clear REPO: none of the site's libraries warns that it is
# being used in a way its next major removes.
#
# A site with no third-party libraries passes at once. Otherwise the step
# needs the checks step's smoke test in a strict form, one that fails on any
# console warning (names in ../NAMES.md): the "JS strict tests:" command from
# JS_LIBRARIES.md, or else a dated testing-js-since-<date>.sh or .js. The site
# runs it. This check never does: a check reads the code and the servers and
# loads no pages. The proof is the line the site records after a strict run,
# "Deprecation warnings clear at <commit>" in JS_LIBRARIES.md, which passes
# while the commit is in the checkout's history and the site loads exactly
# the libraries it loaded then.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

# py WHAT: the reader's answer about this checkout, or "x <why>".
py() {
  python3 -I -B - "$HERE" "$REPO" "$@" <<'PY'
import sys
sys.path.insert(0, sys.argv[1])
import js_scripts as j
try:
    repo, what = sys.argv[2], sys.argv[3]
    if what == "count":
        print(len(j.libraries(repo)))
    elif what == "same":
        print("yes" if j.libraries(repo) == j.libraries(repo, sys.argv[4]) else "no")
except Exception as e:  # noqa: BLE001
    print(f"x {type(e).__name__}: {e}")
PY
}

count="$(py count)"
[[ "$count" =~ ^[0-9]+$ ]] || unknown "could not read the site's scripts: ${count#x }"
[ "$count" -gt 0 ] || pass "the site loads no third-party libraries"

found="$(python3 -I -B "$HERE/js_scripts.py" smoke "$REPO")"
kind="${found%%$'\t'*}"
value="${found#*$'\t'}"
case "$kind" in
  strict|sh|js) ;;
  plain) not_yet "JS_LIBRARIES.md names \"JS tests:\" but no \"JS strict tests: <command>\" that fails on warnings" ;;
  none) not_yet "no smoke test yet; the JavaScript checks step comes first" ;;
  bad) not_yet "$value" ;;
  *) unknown "could not look for the smoke test: $value" ;;
esac

# The site ran the strict smoke test; the commit it recorded is the proof.
[ -f "$REPO/JS_LIBRARIES.md" ] || not_yet "no JS_LIBRARIES.md to record the warnings in"
sha="$(grep -Eo 'Deprecation warnings clear at [0-9a-f]{7,40}' "$REPO/JS_LIBRARIES.md" | tail -1 | awk '{print $NF}')"
[ -n "$sha" ] || not_yet "JS_LIBRARIES.md has no \"Deprecation warnings clear at <commit>\" line"
full="$(git -C "$REPO" rev-parse --verify -q "$sha^{commit}" 2>/dev/null)" \
  || not_yet "JS_LIBRARIES.md records ${sha}, which is not a commit in this checkout"
git -C "$REPO" merge-base --is-ancestor "$full" HEAD 2>/dev/null \
  || not_yet "JS_LIBRARIES.md records ${sha:0:7}, which is not in the checkout's history"
same="$(py same "$full")"
case "$same" in
  yes) pass "warnings recorded clear at ${full:0:7}, and the site loads the same libraries now" ;;
  no) not_yet "the site's libraries changed since ${full:0:7}, where JS_LIBRARIES.md records the warnings clear" ;;
  *) unknown "could not compare the libraries: ${same#x }" ;;
esac
