#!/usr/bin/env bash
# memo: commit
# rector_types REPO: rector.php applies the type-declaration set and a dry run
# finds nothing left to change.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[ -f "$REPO/rector.php" ] || not_yet "no rector.php"
grep -q "TYPE_DECLARATION" "$REPO/rector.php" || not_yet "rector.php does not apply the type-declaration set"
rector_dry_run "$REPO"
rector_verdict "Rector's type declarations"
