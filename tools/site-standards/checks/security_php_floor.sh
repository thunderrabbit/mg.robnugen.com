#!/usr/bin/env bash
# memo: commit
# security_php_floor REPO VERSION: the site refuses to run on a PHP older
# than VERSION (like 8.6).
#
# Done when prepend.php calls requireFloor(), the tracked class that defines
# it holds a PHP_VERSION_ID (the first five-digit number in the file) at or
# above VERSION, and any register.php that does not include prepend.php
# calls it too.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[[ "${2:-}" =~ ^([0-9]+)\.([0-9]+)$ ]] || unknown "the step's param must be a PHP version like 8.6"
want="$2"
want_id=$(( BASH_REMATCH[1] * 10000 + BASH_REMATCH[2] * 100 ))

[ -n "$(git -C "$REPO" ls-files -- prepend.php 2>/dev/null)" ] || not_yet "no prepend.php to put the floor in"
[ -n "$(php_hits 'requireFloor[[:space:]]*\(' prepend.php)" ] \
  || not_yet "prepend.php does not call requireFloor(), so the site runs on whatever PHP serves it"

defined="$(php_hits 'function[[:space:]]+requireFloor[[:space:]]*\(')"
[ -n "$defined" ] || not_yet "prepend.php calls requireFloor(), but no tracked class defines it"
class="$(files_of "$defined" | head -1)"
floor="$(grep -Eo '\b[0-9]{5}\b' "$REPO/$class" | head -1 || true)"
[ -n "$floor" ] || unknown "$class holds no PHP_VERSION_ID to read the floor from"
[ "$floor" -ge "$want_id" ] || not_yet "$class sets the floor at $floor, below PHP $want ($want_id)"

while IFS= read -r page; do
  [ -n "$page" ] || continue
  grep -q 'prepend\.php' "$REPO/$page" && continue
  [ -n "$(php_hits 'requireFloor[[:space:]]*\(' "$page")" ] \
    || not_yet "$page runs without prepend.php and does not call requireFloor()"
done < <(git -C "$REPO" ls-files 2>/dev/null | grep -E '(^|/)register\.php$' \
  | grep -Ev '^(vendor|Tests|tests|templates|classes)/' || true)
pass "$class refuses any PHP below $want, and every entry point asks it first"
