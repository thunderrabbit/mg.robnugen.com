#!/usr/bin/env bash
# checks_in_place REPO: the repo has phpcs, PHPStan and a Unit suite set up,
# and its pre-commit hook is switched on.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[ -f "$REPO/phpcs.xml" ] || not_yet "no phpcs.xml"
[ -f "$REPO/phpstan.neon" ] || [ -f "$REPO/phpstan.neon.dist" ] || not_yet "no phpstan.neon"
[ -d "$REPO/Tests/Unit" ] || not_yet "no Unit suite under Tests/Unit/"
[ -n "$(find "$REPO/Tests/Unit" -name '*Test.php' -print -quit 2>/dev/null)" ] || not_yet "the Unit suite has no tests"
[ -f "$REPO/.githooks/pre-commit" ] || not_yet "no .githooks/pre-commit"
[ "$(git -C "$REPO" config core.hooksPath 2>/dev/null)" = ".githooks" ] \
  || not_yet "the pre-commit hook is not switched on (git config core.hooksPath .githooks)"
pass "phpcs, PHPStan, Unit suite and pre-commit hook are set up"
