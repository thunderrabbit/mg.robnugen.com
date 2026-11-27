#!/usr/bin/env bash
# phpmyadmin_script_removed REPO: no file named phpmyadmin* is left at the
# repo's root, reminder or not.
#
# No memo: most sites gitignore these scripts, so they are not in the commit.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

left="$(find "$REPO" -maxdepth 1 -type f -name 'phpmyadmin*' -printf '%f\n' | sort | head -3 | tr '\n' ' ')"
[ -z "$left" ] || not_yet "still here: ${left% }"
pass "no phpmyadmin script at the root"
