#!/usr/bin/env bash
# suite_passes_on_php REPO X: the repo's own safe suite (its root-level
# safe-suite.sh) passes against the site's test domain, which is already on PHP X.
#
# This is the one check that runs a suite, so it runs only when it must: the
# site has a test domain, that domain serves X, the code compiles on both PHPs,
# the test domain runs the checkout's own commit, and no result is already
# remembered for that commit. Every other case answers at once, without Docker.
# Facts come from the scanner as SITE_* variables. mg never learns how a
# site tests; safe-suite.sh does, and it is the only thing run.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
X="${2:-}"
[[ "$X" =~ ^[0-9]+\.[0-9]+$ ]] || unknown "this check needs a PHP version such as 8.5"

[ -n "${SITE_TEST_DOMAIN:-}" ] || pass "no test domain; nothing to run"

test_php="$(printf '%s' "${SITE_TEST_PHP:-}" | grep -Eo '^[0-9]+\.[0-9]+' || true)"
[ -n "$test_php" ] || not_yet "the test domain serves no PHP version in the sweep"
[ "$(printf '%s\n%s\n' "$test_php" "$X" | sort -V | head -1)" = "$X" ] \
  || not_yet "the test domain is not on PHP $X yet (it serves $test_php)"

"$HERE/code_runs_on_php.sh" "$REPO" "$X" >/dev/null 2>&1 \
  || unknown "the code does not compile on both PHPs yet"

[ -f "$REPO/safe-suite.sh" ] || unknown "the repo has no safe-suite.sh"
# A result describes the commit only if the tree is that commit. A dirty tree
# would run the suite on edits and remember the answer under HEAD.
[ -z "$(git --no-optional-locks -C "$REPO" status --porcelain 2>/dev/null)" ] \
  || unknown "the checkout has uncommitted changes, so a result would not describe its commit"
[ -x "$STANDARDS_MCP/run-site-suite.sh" ] || unknown "standards-mcp has no run-site-suite.sh"

alias_="${SITE_TEST_SSH_ALIAS:-}"
dir="${SITE_TEST_DIRECTORY:-}"
[[ "$alias_" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || unknown "no ssh login for the test domain to ask which commit it runs"
need_server_dir "$dir" "the test domain's"
head="$(git -C "$REPO" rev-parse HEAD 2>/dev/null)" || unknown "could not read the checkout's commit"
# shellcheck disable=SC2086
there="$(${SCAN_SSH:-ssh -o BatchMode=yes -o ConnectTimeout=15} "$alias_" "git -c safe.directory='*' --no-optional-locks -C '$dir' rev-parse HEAD" 2>/dev/null </dev/null | head -1)"
[[ "$there" =~ ^[0-9a-f]{40}$ ]] || unknown "could not ask the test domain which commit it runs"
[ "$there" = "$head" ] || unknown "the test domain runs ${there:0:7}, the checkout is ${head:0:7}"

# A result is remembered per site, commit and PHP, so an unchanged commit is
# never run twice. The scanner's own temp dir would not outlive a scan.
shortcode="${SITE_SHORTCODE:-}"
[[ "$shortcode" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]] || unknown "no site shortcode to remember a result under"
state="${XDG_STATE_HOME:-$HOME/.local/state}/site-standards"
memo="$state/suite-$shortcode"
if [ -f "$memo" ]; then
  read -r m_commit m_php m_rc m_line < "$memo" || true
  if [ "$m_commit" = "$head" ] && [ "$m_php" = "$X" ]; then
    [ "$m_rc" = 0 ] && pass "$m_line"
    not_yet "$m_line"
  fi
fi

out="$(SITE_TEST_DOMAIN="$SITE_TEST_DOMAIN" "$STANDARDS_MCP/run-site-suite.sh" "$REPO" "$X" 2>/dev/null)"
rc=$?
line="${out##*$'\n'}"
line="${line//$'\n'/ }"
case "$rc" in
  0|1)
    mkdir -p "$state" && printf '%s %s %s %s\n' "$head" "$X" "$rc" "$line" > "$memo"
    [ "$rc" = 0 ] && pass "$line"
    not_yet "$line"
    ;;
  *) unknown "$line" ;;
esac
