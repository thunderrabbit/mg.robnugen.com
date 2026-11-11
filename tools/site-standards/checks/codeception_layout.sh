#!/usr/bin/env bash
# memo: commit
# codeception_layout REPO: the tests are in Tests/, laid out the Codeception 5
# way.
#
# Done when nothing is tracked under a top-level tests/ spelled any other way
# than `Tests/`, and, for a repo with a codeception.yml, that file names the
# `Tests` namespace and the Tests/, Tests/Support, Tests/Support/Data and
# Tests/_output paths, nothing is tracked under Tests/_support or Tests/_data,
# and every suite file is Tests/<Capitalized>.suite.yml. A repo with no tests
# at all passes: there is nothing to move.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

tracked="$(git -C "$REPO" ls-files 2>/dev/null)"
count() { printf '%s' "$1" | grep -c . || true; }

stray="$(printf '%s\n' "$tracked" | grep -iE '^tests/' | grep -vE '^Tests/' || true)"
[ -z "$stray" ] || {
  dir="$(printf '%s\n' "$stray" | head -1 | cut -d/ -f1)"
  not_yet "$(count "$stray") tracked file(s) under $dir/; Codeception 5 keeps tests in Tests/"
}

yml="$REPO/codeception.yml"
git -C "$REPO" ls-files --error-unmatch -- codeception.yml >/dev/null 2>&1 \
  || pass "no Codeception suite to lay out"

has() { grep -Eq "$1" "$yml"; }
has '^namespace:[[:space:]]*Tests[[:space:]]*$' || not_yet "codeception.yml does not say namespace: Tests"
has '^support_namespace:[[:space:]]*Support[[:space:]]*$' || not_yet "codeception.yml does not say support_namespace: Support"
for want in 'tests: Tests' 'output: Tests/_output' 'data: Tests/Support/Data' 'support: Tests/Support'; do
  has "^[[:space:]]+${want%%:*}:[[:space:]]*${want#*: }[[:space:]]*\$" \
    || not_yet "codeception.yml's paths do not say $want"
done

old="$(printf '%s\n' "$tracked" | grep -E '^Tests/(_support|_data)/' || true)"
[ -z "$old" ] || not_yet "$(count "$old") tracked file(s) still under Tests/_support or Tests/_data"

suites="$(printf '%s\n' "$tracked" | grep -E '^Tests/[^/]+\.suite\.yml$' | grep -vE '^Tests/[A-Z]' || true)"
[ -z "$suites" ] || not_yet "$(printf '%s\n' "$suites" | head -1) is not Tests/<Capitalized>.suite.yml"

pass "Tests/ has the Codeception 5 layout"
