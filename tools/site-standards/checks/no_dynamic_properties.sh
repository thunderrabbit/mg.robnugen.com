#!/usr/bin/env bash
# memo: commit
# no_dynamic_properties REPO: PHPStan finds no undefined properties, counting
# both what a run reports and what the repo's baselines hide. PHP 8.2
# deprecates creating a property a class doesn't declare (PHP 9 makes it an
# error), and PHPStan reports each one as "Access to an undefined property".
#
# PHPStan below level 2 looks only at properties on $this, so at levels 0
# and 1 a clean count can't tell; it says so rather than passing.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

configs="$(python3 -I "$HERE/phpstan_neon.py" configs "$REPO")" || unknown "could not read the PHPStan configs"
[ -n "$configs" ] || not_yet "no phpstan.neon"
[ -f "$REPO/vendor/bin/phpstan" ] || unknown "PHPStan is not installed in vendor/"

hidden=0
lowest=""
while IFS= read -r config; do
  count="$(python3 -I "$HERE/phpstan_neon.py" baselined-undefined "$REPO" "$config")" \
    || unknown "$config could not be read"
  hidden=$(( hidden + count ))
  level="$(python3 -I "$HERE/phpstan_neon.py" level "$REPO/$config" || true)"
  case "$level" in
    max) level=10 ;;
    ''|*[!0-9]*) level=0 ;;
  esac
  if [ -z "$lowest" ] || [ "$level" -lt "$lowest" ]; then
    lowest="$level"
  fi
done <<< "$configs"

out="$("$STANDARDS_MCP/run-phpstan.sh" "$REPO" 2>&1)" || true
case "$out" in *"GATE SKIP"*) unknown "PHPStan did not run: no config the runner recognises" ;; esac
ran="$(printf '%s\n' "$out" | sed -n 's/^##PHPSTAN_END .* rc=\([0-9]*\)$/\1/p' | sort -u | tr '\n' ' ')"
case "$ran" in
  "0 "|"1 "|"0 1 ") ;;
  "") unknown "PHPStan did not run (is Docker up, and the runner image built?)" ;;
  *) unknown "PHPStan stopped with exit ${ran% }" ;;
esac
reported="$(printf '%s\n' "$out" | grep -c 'Access to an undefined property' || true)"
total=$(( hidden + reported ))

[ "$total" -eq 0 ] || not_yet "$total undefined propert$( [ "$total" -eq 1 ] && echo y || echo ies) ($reported reported, $hidden in baselines); declare them"
[ "$lowest" -ge 2 ] \
  || unknown "none found, but PHPStan at level $lowest sees properties on \$this only; level 2 sees them all"
pass "no undefined properties, at PHPStan level $lowest or above"
