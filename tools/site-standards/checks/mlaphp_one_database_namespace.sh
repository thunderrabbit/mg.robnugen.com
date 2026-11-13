#!/usr/bin/env bash
# memo: commit
# mlaphp_one_database_namespace REPO: `Database` is the only namespace that
# means "database".
#
# Done when no tracked PHP file outside vendor/ declares a namespace whose
# first segment is another word for the database (Db, Dbal, Sql, MySQL, Pdo,
# Data, Datastore, Persistence, in any letter case). Sub-namespaces of
# `Database` are not looked at, and neither is where a feature keeps its own
# repository.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

pattern='^[[:space:]]*namespace[[:space:]]+(db|dbal|sql|mysql|pdo|data|datastore|persistence)([[:space:]]*[\;{]|[[:space:]]*$)'
hits="$(git -C "$REPO" ls-files -z -- '*.php' ':!:vendor/**' 2>/dev/null \
  | (cd "$REPO" && xargs -0 -r grep -EHni "$pattern") || true)"
[ -z "$hits" ] || {
  files="$(printf '%s\n' "$hits" | cut -d: -f1 | sort -u | wc -l)"
  names="$(printf '%s\n' "$hits" | cut -d: -f3- \
    | sed -E 's/^[[:space:]]*[Nn][Aa][Mm][Ee][Ss][Pp][Aa][Cc][Ee][[:space:]]+([A-Za-z0-9_]+).*/\1/' \
    | sort -u | paste -sd, - | sed 's/,/, /g')"
  not_yet "$files file(s) in namespace $names, first $(printf '%s\n' "$hits" | head -1 | cut -d: -f1)"
}
pass "Database is the only database namespace"
