#!/usr/bin/env bash
# js_soaked_on_production REPO [DAYS]: the commit the site's server runs has
# run there for at least DAYS working days (default 5). Asks the server, over
# the scanner's ssh (SITE_SERVER_SSH_ALIAS, SITE_SERVER_DIRECTORY), when HEAD
# last moved, from its reflog. A site with no third-party libraries passes.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
days="${2:-5}"
[[ "$days" =~ ^[0-9]+$ ]] || unknown "the step's param is not a number of days"

count="$(python3 -I -B - "$HERE" "$REPO" <<'PY'
import sys
sys.path.insert(0, sys.argv[1])
import js_scripts as j
try:
    print(len(j.libraries(sys.argv[2])))
except Exception as e:  # noqa: BLE001
    print(f"x {e}")
PY
)"
[[ "$count" =~ ^[0-9]+$ ]] || unknown "could not read the site's scripts: ${count#x }"
[ "$count" -gt 0 ] || pass "the site loads no third-party libraries"

alias_="${SITE_SERVER_SSH_ALIAS:-}"
dir="${SITE_SERVER_DIRECTORY:-}"
[[ "$alias_" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || unknown "no ssh login for the server to ask when it last changed"
[[ "$dir" =~ ^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9._-]+)*$ && "$dir" != *..* ]] || unknown "the server's directory is not one this check will use"
# shellcheck disable=SC2086
answer="$(${SCAN_SSH:-ssh -o BatchMode=yes -o ConnectTimeout=15} "$alias_" \
  "git --no-optional-locks -C '$dir' reflog show --date=unix -1 HEAD" 2>/dev/null </dev/null | head -1)"
[[ "$answer" =~ ^([0-9a-f]{7,40})\ HEAD@\{([0-9]+)\} ]] \
  || unknown "the server has no record of when HEAD last moved (deployed by copying files?)"
sha="${BASH_REMATCH[1]}"
moved="${BASH_REMATCH[2]}"

out="$(python3 -I -B - "$moved" "$days" "$sha" <<'PY'
import datetime, sys
moved = datetime.datetime.fromtimestamp(int(sys.argv[1]), datetime.timezone.utc)
need, sha = int(sys.argv[2]), sys.argv[3][:7]
now = datetime.datetime.now(datetime.timezone.utc)
day, worked = moved.date(), 0
while day < now.date():
    day += datetime.timedelta(days=1)
    if day.weekday() < 5:
        worked += 1
since = moved.strftime("%Y-%m-%d")
if worked >= need:
    print(f"0 production has run {sha} since {since}, {worked} working days")
else:
    print(f"1 production has run {sha} only since {since}, {worked} of {need} working days")
PY
)"
case "${out%% *}" in
  0) pass "${out#* }" ;;
  *) not_yet "${out#* }" ;;
esac
