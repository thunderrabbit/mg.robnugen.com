#!/usr/bin/env bash
# memo: commit
# admin_migrations_chip REPO: the menu's Admin link shows an admin how many
# migrations wait to be applied.
#
# Done when prepend.php works out $admin_count from getPendingMigrations(),
# the Template class carries admin_count to every template, and a template
# draws $admin_count. Only tracked files are read.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

tracked() { git -C "$REPO" ls-files -- "$@" 2>/dev/null; }

[ -n "$(tracked prepend.php)" ] || not_yet "no prepend.php to count pending migrations in"
grep -Eq '\$admin_count[[:space:]]*=' "$REPO/prepend.php" \
  || not_yet "prepend.php never sets \$admin_count"
grep -q 'getPendingMigrations' "$REPO/prepend.php" \
  || not_yet "prepend.php sets \$admin_count, but not from getPendingMigrations()"

carried=''
while IFS= read -r class; do
  grep -q 'admin_count' "$REPO/$class" && carried="$class"
done < <(tracked classes | grep -E '(^|/)Template\.php$')
[ -n "$carried" ] \
  || not_yet "no Template class carries admin_count, so each page would have to remember it"

drawn="$(git -C "$REPO" grep -lE '\$admin_count' -- templates 2>/dev/null | head -1)"
[ -n "$drawn" ] || not_yet "nothing under templates/ draws \$admin_count"
pass "prepend.php counts pending migrations, $carried carries the count and $drawn draws it"
