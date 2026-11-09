#!/usr/bin/env bash
# memo: commit
# rector_level REPO X: rector.php applies the PHP X level set (for example
# 8.5 -> UP_TO_PHP_85) and a dry run finds nothing left to change.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"
X="${2:-}"
[[ "$X" =~ ^[0-9]+\.[0-9]+$ ]] || unknown "this check needs a PHP version such as 8.5"

[ -f "$REPO/rector.php" ] || not_yet "no rector.php"
grep -q "UP_TO_PHP_${X//./}\b" "$REPO/rector.php" || not_yet "rector.php does not apply the PHP $X level set"
rector_dry_run "$REPO"
rector_verdict "Rector's PHP $X rules"
