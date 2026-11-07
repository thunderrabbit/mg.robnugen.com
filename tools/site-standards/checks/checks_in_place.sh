#!/usr/bin/env bash
# checks_in_place REPO: the repo has phpcs, PHPStan and a Unit suite set up,
# and a tracked pre-commit hook that is switched on (core.hooksPath names the
# directory; see need_hooks in ../lib.sh).
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[ -f "$REPO/phpcs.xml" ] || not_yet "no phpcs.xml"
[ -f "$REPO/phpstan.neon" ] || [ -f "$REPO/phpstan.neon.dist" ] || not_yet "no phpstan.neon"
[ -d "$REPO/Tests/Unit" ] || not_yet "no Unit suite under Tests/Unit/"
[ -n "$(find "$REPO/Tests/Unit" -name '*Test.php' -print -quit 2>/dev/null)" ] || not_yet "the Unit suite has no tests"
need_hooks
[ -f "$HOOKS/pre-commit" ] || not_yet "no pre-commit hook in ${HOOKS#"$REPO/"}/"
pass "phpcs, PHPStan, Unit suite and pre-commit hook are set up"
