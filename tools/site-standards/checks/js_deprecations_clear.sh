#!/usr/bin/env bash
# js_deprecations_clear REPO: none of the site's libraries warns that it is
# being used in a way its next major removes.
#
# A site with no third-party libraries passes at once. Otherwise the step
# needs the checks step's smoke test (names in ../NAMES.md). With a test
# domain, its strict form is run there, so any console warning keeps the step
# open: the "JS strict tests:" command from JS_LIBRARIES.md, or else the
# dated testing-js-since-<date>.sh with --strict. It runs only when the test
# domain runs the checkout's own commit. A pass is remembered per commit, so
# the suite is not driven again for a commit it already passed on; a failure
# is not, because a browser suite can fail for a reason that is not the
# site's (a flaky run, a sleeping test domain), and the next scan should
# look again. A site with a smoke test but no test
# domain records "Deprecation warnings clear at <commit>" in JS_LIBRARIES.md;
# that passes while the commit is in the checkout's history and the site
# loads exactly the libraries it loaded then.
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
  strict) command=(sh -c "$value") ;;
  sh) command=("./$value" --strict) ;;
  js) unknown "$value is a Node smoke test, which the checks can't run yet" ;;
  plain) not_yet "JS_LIBRARIES.md names \"JS tests:\" but no \"JS strict tests: <command>\" that fails on warnings" ;;
  none) not_yet "no smoke test yet; the JavaScript checks step comes first" ;;
  bad) not_yet "$value" ;;
  *) unknown "could not look for the smoke test: $value" ;;
esac

if [ -n "${SITE_TEST_DOMAIN:-}" ]; then
  [[ "$SITE_TEST_DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || unknown "the test domain's name is not one this check will use"
  [ -z "$(git --no-optional-locks -C "$REPO" status --porcelain 2>/dev/null)" ] \
    || unknown "the checkout has uncommitted changes, so a result would not describe its commit"
  [ -x "$STANDARDS_MCP/run-webdriver.sh" ] || unknown "standards-mcp has no run-webdriver.sh"
  alias_="${SITE_TEST_SSH_ALIAS:-}"
  dir="${SITE_TEST_DIRECTORY:-}"
  [[ "$alias_" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || unknown "no ssh login for the test domain to ask which commit it runs"
  need_server_dir "$dir" "the test domain's"
  head="$(git -C "$REPO" rev-parse HEAD 2>/dev/null)" || unknown "could not read the checkout's commit"
  there="$(python3 -I -B "$HERE/../server_facts.py" head "$alias_" "$SERVER_DIR" 2>/dev/null </dev/null | head -1)"
  [[ "$there" =~ ^[0-9a-f]{40}$ ]] || unknown "could not ask the test domain which commit it runs"
  [ "$there" = "$head" ] || unknown "the test domain runs ${there:0:7}, the checkout is ${head:0:7}"

  shortcode="${SITE_SHORTCODE:-}"
  [[ "$shortcode" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]] || unknown "no site shortcode to remember a result under"
  state="${XDG_STATE_HOME:-$HOME/.local/state}/site-standards"
  memo="$state/js-strict-smoke-$shortcode"
  if [ -f "$memo" ]; then
    read -r m_commit m_line < "$memo" || true
    [ "$m_commit" = "$head" ] && pass "$m_line"
  fi

  out="$(TEST_BASE_URL="https://$SITE_TEST_DOMAIN" "$STANDARDS_MCP/run-webdriver.sh" "$REPO" "${command[@]}" 2>&1)"
  rc=$?
  line="${out##*$'\n'}"
  case "$out" in *"GATE ERROR"*) unknown "the smoke test could not start: $line" ;; esac
  case "$rc" in
    0) line="the strict smoke test passes on $SITE_TEST_DOMAIN at ${head:0:7}" ;;
    1) line="the strict smoke test fails on $SITE_TEST_DOMAIN: $line" ;;
    *) unknown "the strict smoke test did not run (exit $rc): $line" ;;
  esac
  if [ "$rc" = 0 ]; then
    mkdir -p "$state" && printf '%s %s\n' "$head" "${line//$'\n'/ }" > "$memo"
    pass "$line"
  fi
  not_yet "$line"
fi

# A smoke test but no test domain to run it on: the recorded commit is the proof.
[ -f "$REPO/JS_LIBRARIES.md" ] || not_yet "no test domain to run the smoke test on, and no JS_LIBRARIES.md to record the warnings in"
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
