#!/usr/bin/env bash
# memo: commit
# code_runs_on_php REPO X: every PHP file compiles on PHP X and on the PHP the
# site is served on today (SITE_PHP_CURRENT, set by the scanner from the
# sweep), so the code can be deployed before the panel switch without
# breaking the gap between the two. It proves compilation only: behaviour is
# the test-suite step's job. Linting runs through standards-mcp, read-only.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"
X="${2:-}"
[[ "$X" =~ ^[0-9]+\.[0-9]+$ ]] || unknown "this check needs a PHP version such as 8.5"
current="${SITE_PHP_CURRENT:-}"
[[ "$current" =~ ^[0-9]+\.[0-9]+ ]] || unknown "its current PHP is not in the sweep"
current="$(printf '%s' "$current" | grep -Eo '^[0-9]+\.[0-9]+')"
[ -x "$STANDARDS_MCP/run-php-lint.sh" ] || unknown "standards-mcp has no run-php-lint.sh"

# lint_on VERSION: stops the check unless every file compiles on it.
lint_on() {
  local out rc=0
  out="$("$STANDARDS_MCP/run-php-lint.sh" "$REPO" "$1" 2>/dev/null)" || rc=$?
  local line="${out##*$'\n'}"
  case "$rc" in
    0) ;;
    1) not_yet "does not compile on PHP $1: $line" ;;
    *) unknown "$line" ;;
  esac
}

lint_on "$X"
if [ "$current" != "$X" ] && [ "$(printf '%s\n%s\n' "$current" "$X" | sort -V | head -1)" = "$current" ]; then
  lint_on "$current"
  pass "compiles on PHP $current and $X"
fi
pass "compiles on PHP $X (the site already serves $current)"
