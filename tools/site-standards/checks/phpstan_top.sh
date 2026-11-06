#!/usr/bin/env bash
# phpstan_top REPO: PHPStan is at level 10 with no baseline, and a run is clean.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

config="$REPO/phpstan.neon"
[ -f "$config" ] || config="$REPO/phpstan.neon.dist"
[ -f "$config" ] || not_yet "no phpstan.neon"

level="$(sed -n 's/^[[:space:]]*level:[[:space:]]*\([A-Za-z0-9]*\).*/\1/p' "$config" | head -1)"
case "$level" in
  10|max) ;;
  "") not_yet "phpstan.neon sets no level" ;;
  *) not_yet "PHPStan is at level $level" ;;
esac
if grep -qi 'baseline' "$config" || compgen -G "$REPO/phpstan*baseline*.neon" > /dev/null; then
  not_yet "PHPStan has a baseline"
fi

# From here on a failure may be the tooling's, not the site's: say "could not
# tell" unless PHPStan itself ran and reported findings (its exit 1).
[ -f "$REPO/vendor/bin/phpstan" ] || unknown "PHPStan is not installed in vendor/"
out="$("$STANDARDS_MCP/run-phpstan.sh" "$REPO" 2>&1)" || true
case "$out" in *"GATE SKIP"*) unknown "PHPStan did not run: no config the runner recognises" ;; esac
ran="$(printf '%s\n' "$out" | sed -n 's/^##PHPSTAN_END .* rc=\([0-9]*\)$/\1/p' | sort -u | tr '\n' ' ')"
case "$ran" in
  "0 ") pass "level $level, no baseline, run clean" ;;
  "1 "|"0 1 ") not_yet "PHPStan reports findings at level $level" ;;
  "") unknown "PHPStan did not run (is Docker up, and the runner image built?)" ;;
  *) unknown "PHPStan stopped with exit ${ran% }" ;;
esac
