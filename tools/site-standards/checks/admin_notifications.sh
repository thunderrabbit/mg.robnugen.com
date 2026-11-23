#!/usr/bin/env bash
# memo: commit
# admin_notifications REPO: the site has a notifications table for its admins,
# counted in the menu's Admin link once due.
#
# Done when a schema file under db_schemas/ creates the table `notifications`,
# prepend.php adds notifications to $admin_count, and the site has an
# /admin/notifications/ page to complete them on. Only tracked files are read.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

tracked() { git -C "$REPO" ls-files -- "$@" 2>/dev/null; }

schema="$(git -C "$REPO" grep -liE \
  'CREATE TABLE( IF NOT EXISTS)? `?notifications`?[[:space:]]*\(' -- db_schemas 2>/dev/null | head -1)"
[ -n "$schema" ] || not_yet "no schema file under db_schemas/ creates the notifications table"

[ -n "$(tracked prepend.php)" ] || not_yet "no prepend.php to count due notifications in"
grep -Eq '\$admin_count[[:space:]]*=' "$REPO/prepend.php" \
  || not_yet "prepend.php never sets \$admin_count: do the pending migrations chip first"
grep -qi 'notifications' "$REPO/prepend.php" \
  || not_yet "prepend.php does not add due notifications to \$admin_count"

page="$(tracked | grep -E '(^|/)admin/notifications/index\.php$' | head -1)"
[ -n "$page" ] || not_yet "no /admin/notifications/ page to complete a notification on"
pass "$schema creates the table, prepend.php counts what is due and $page completes them"
