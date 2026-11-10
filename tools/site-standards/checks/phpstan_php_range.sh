#!/usr/bin/env bash
# memo: commit
# phpstan_php_range REPO X: every PHPStan config the runner runs sets a
# phpVersion that covers both the PHP the site is served on today
# (SITE_PHP_CURRENT) and X, its target. PHPStan then reports, in one run, code
# that would break on either: a call the target removed, and syntax the
# current PHP doesn't have yet. A site already on its target passes with a
# plain phpVersion for that PHP.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
X="${2:-}"
[[ "$X" =~ ^[0-9]+\.[0-9]+$ ]] || unknown "this check needs a PHP version such as 8.5"
current="$(printf '%s' "${SITE_PHP_CURRENT:-}" | grep -Eo '^[0-9]+\.[0-9]+' || true)"
[ -n "$current" ] || unknown "its current PHP is not in the sweep"

# 8.3 -> 80300, as PHPStan writes it.
id_of() { local v="$1"; printf '%d' "$(( ${v%%.*} * 10000 + ${v#*.} * 100 ))"; }
low="$(id_of "$current")"
high="$(id_of "$X")"
if [ "$low" -gt "$high" ]; then
  # Already past the target: the range only has to cover what it serves.
  high="$low"
fi

configs="$(python3 -I "$HERE/phpstan_neon.py" configs "$REPO")" || unknown "could not read the PHPStan configs"
[ -n "$configs" ] || not_yet "no phpstan.neon"
while IFS= read -r config; do
  rc=0
  range="$(python3 -I "$HERE/phpstan_neon.py" php-version "$REPO/$config")" || rc=$?
  case "$rc" in
    0) ;;
    1) not_yet "$config sets no phpVersion; it should cover $current to $X" ;;
    *) unknown "$config could not be read" ;;
  esac
  min="${range% *}"
  max="${range#* }"
  [ "$min" -le "$low" ] && [ "$max" -ge "$high" ] \
    || not_yet "$config checks PHP $min to $max; it should cover $low ($current) to $high ($X)"
done <<< "$configs"
pass "PHPStan checks PHP $current to $X in one run"
