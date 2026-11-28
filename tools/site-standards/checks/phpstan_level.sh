#!/usr/bin/env bash
# memo: commit
# phpstan_level REPO LEVEL: PHPStan is at LEVEL (0 to 10) or above with no
# baseline, and a run is clean. A site further up the ladder has done every
# level below it.
#
# A site with one config per level (phpstan-level-N.neon, each over its own
# directories) is at the lowest of them: that is the level all of its code is
# held to. A baseline in any tier counts, because nothing here can tell which
# level's findings it hides. A baseline file with no entries hides nothing and
# is not one.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

want="${2:-}"
[[ "$want" =~ ^([0-9]|10)$ ]] || unknown "phpstan_level needs a level from 0 to 10"

rc=0
lowest="$(python3 -I "$HERE/phpstan_neon.py" lowest-level "$REPO")" || rc=$?
case "$rc" in
  0) ;;
  1) not_yet "no phpstan.neon" ;;
  *) unknown "could not read the PHPStan configs" ;;
esac
level="${lowest%% *}"
config="${lowest#* }"
case "$level" in
  max) at=10 ;;
  [0-9]|10) at="$level" ;;
  unset) not_yet "$config sets no level" ;;
  *) unknown "$config's level is $level, which is not one this check knows" ;;
esac
tiers="$(python3 -I "$HERE/phpstan_neon.py" configs "$REPO" | wc -l)"
where="$config is at level $level"
[ "$tiers" -le 1 ] || where="its lowest tier, $config, is at level $level"
if [ "$at" -lt "$want" ]; then
  [ "$tiers" -le 1 ] || not_yet "PHPStan is at level $level, its lowest tier ($config)"
  not_yet "PHPStan is at level $level"
fi
baselines="$(python3 -I "$HERE/phpstan_neon.py" baselines "$REPO")" || unknown "could not read the PHPStan baselines"
if [ -n "$baselines" ]; then
  not_yet "PHPStan has a baseline: $(printf '%s\n' "$baselines" | awk '
    { said = ($2 == 0) ? $1 " has entries" : $1 " hides " $2 " finding" ($2 == 1 ? "" : "s")
      line = line (NR > 1 ? ", " : "") said }
    END { print line }')"
fi
reached="level $want"
[ "$at" -eq "$want" ] || reached="level $want ($where)"

# From here on a failure may be the tooling's, not the site's: say "could not
# tell" unless PHPStan itself ran and reported findings (its exit 1).
[ -f "$REPO/vendor/bin/phpstan" ] || unknown "PHPStan is not installed in vendor/"
phpstan_run "$REPO"
case "$PHPSTAN_OUT" in *"GATE SKIP"*) unknown "PHPStan did not run: no config the runner recognises" ;; esac
ran="$(printf '%s\n' "$PHPSTAN_OUT" | sed -n 's/^##PHPSTAN_END .* rc=\([0-9]*\)$/\1/p' | sort -u | tr '\n' ' ')"
case "$ran" in
  "0 ") pass "$reached, no baseline, run clean" ;;
  "1 "|"0 1 ")
    [ "$tiers" -le 1 ] || not_yet "PHPStan reports findings"
    not_yet "PHPStan reports findings at level $level" ;;
  "") unknown "PHPStan did not run (is Docker up, and the runner image built?)" ;;
  *) unknown "PHPStan stopped with exit ${ran% }" ;;
esac
