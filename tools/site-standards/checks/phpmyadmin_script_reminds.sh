#!/usr/bin/env bash
# phpmyadmin_script_reminds REPO: no file named phpmyadmin* at the repo's
# root does anything but remind.
#
# DreamHost retired phpMyAdmin, so a script that signs in to it leads
# nowhere. The step turns each one into a reminder to type ql[TAB], which
# opens the site's database in DBeaver (tools/db-open/db-open.sh in mg).
#
# A file is judged by what it does: every line that is not blank or a comment
# must be an `echo`, and one of them must say ql[TAB]. An editor's backup
# (phpmyadmin-open.sh~) still holds the old code, so it must go. A repo with
# no such file has nothing to remind with, and passes.
#
# No memo: most sites gitignore these scripts, so they are not in the commit.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

count=0
while IFS= read -r file; do
  name="$(basename "$file")"
  case "$name" in
    *~ | *.bak | *.orig) not_yet "delete $name: a backup still holding the old script" ;;
  esac
  code="$(grep -v -E '^[[:space:]]*(#|$)' "$file")"
  other="$(printf '%s\n' "$code" | grep -v -E '^[[:space:]]*echo[[:space:]]' | head -1)"
  [ -z "$other" ] || not_yet "$name still does more than remind"
  printf '%s\n' "$code" | grep -q -F 'ql[TAB]' || not_yet "$name does not say to type ql[TAB]"
  count=$((count + 1))
done < <(find "$REPO" -maxdepth 1 -type f -name 'phpmyadmin*' | sort)

[ "$count" -gt 0 ] || pass "no phpmyadmin script at the root"
pass "$count phpmyadmin script(s), each only a reminder to type ql[TAB]"
