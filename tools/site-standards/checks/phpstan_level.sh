#!/usr/bin/env bash
# memo: commit
# phpstan_level REPO LEVEL: PHPStan is at LEVEL (1 to 10) or above with no
# baseline, and a run is clean. A site further up the ladder has done every
# level below it.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

want="${2:-}"
[[ "$want" =~ ^([1-9]|10)$ ]] || unknown "phpstan_level needs a level from 1 to 10"

config="$REPO/phpstan.neon"
[ -f "$config" ] || config="$REPO/phpstan.neon.dist"
[ -f "$config" ] || not_yet "no phpstan.neon"

level="$(sed -n 's/^[[:space:]]*level:[[:space:]]*\([A-Za-z0-9]*\).*/\1/p' "$config" | head -1)"
case "$level" in
  max) at=10 ;;
  [0-9]|10) at="$level" ;;
  "") not_yet "phpstan.neon sets no level" ;;
  *) unknown "phpstan.neon's level is $level, which is not one this check knows" ;;
esac
[ "$at" -ge "$want" ] || not_yet "PHPStan is at level $level"
if grep -qi 'baseline' "$config" || compgen -G "$REPO/phpstan*baseline*.neon" > /dev/null; then
  not_yet "PHPStan has a baseline"
fi
reached="level $want"
[ "$at" -eq "$want" ] || reached="level $want (phpstan.neon is at $level)"

# From here on a failure may be the tooling's, not the site's: say "could not
# tell" unless PHPStan itself ran and reported findings (its exit 1).
[ -f "$REPO/vendor/bin/phpstan" ] || unknown "PHPStan is not installed in vendor/"
phpstan_run "$REPO"
case "$PHPSTAN_OUT" in *"GATE SKIP"*) unknown "PHPStan did not run: no config the runner recognises" ;; esac
ran="$(printf '%s\n' "$PHPSTAN_OUT" | sed -n 's/^##PHPSTAN_END .* rc=\([0-9]*\)$/\1/p' | sort -u | tr '\n' ' ')"
case "$ran" in
  "0 ") pass "$reached, no baseline, run clean" ;;
  "1 "|"0 1 ") not_yet "PHPStan reports findings at level $level" ;;
  "") unknown "PHPStan did not run (is Docker up, and the runner image built?)" ;;
  *) unknown "PHPStan stopped with exit ${ran% }" ;;
esac
