#!/usr/bin/env bash
# memo: commit
# phpunit_under_codeception REPO: the site's PHPUnit tests are run by
# Codeception, not by a PHPUnit setup of their own.
#
# Done when no phpunit*.xml (or .xml.dist) is tracked at the repo root, and
# composer.json does not ask for phpunit/phpunit without also asking for
# codeception/codeception. A repo with neither passes: there is nothing to
# bring over.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

config="$(git -C "$REPO" ls-files 2>/dev/null | grep -E '^phpunit[^/]*\.xml(\.dist)?$' | head -1 || true)"
[ -z "$config" ] || not_yet "$config is tracked: the tests run under PHPUnit's own config"

composer="$REPO/composer.json"
if [ -f "$composer" ] && grep -q '"phpunit/phpunit"' "$composer" \
  && ! grep -q '"codeception/codeception"' "$composer"; then
  not_yet "composer.json asks for phpunit/phpunit but not codeception/codeception"
fi
pass "no PHPUnit setup of its own"
