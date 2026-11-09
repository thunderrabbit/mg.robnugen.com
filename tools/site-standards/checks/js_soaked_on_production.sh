#!/usr/bin/env bash
# js_soaked_on_production REPO [DAYS]: the last library change production
# runs has run there for at least DAYS working days (default 5). A library
# change is a commit that touched JS_LIBRARIES.md, which names every
# third-party script's exact version. Asks the server, over the scanner's ssh
# (SITE_SERVER_SSH_ALIAS, SITE_SERVER_DIRECTORY), for its reflog, and finds
# the first deploy that carried that commit; deploys since then that changed
# no library do not restart the count. A site with no third-party libraries
# passes.
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
need_server_dir "$dir" "the server's"
# shellcheck disable=SC2086
reflog="$(${SCAN_SSH:-ssh -o BatchMode=yes -o ConnectTimeout=15} "$alias_" \
  "git -c safe.directory='*' --no-optional-locks -C '$dir' reflog show --date=unix --format='%H %gd' -200 HEAD" 2>/dev/null </dev/null)"
[[ "$reflog" =~ ^([0-9a-f]{40})\ HEAD@\{[0-9]+\} ]] \
  || unknown "the server has no record of when HEAD last moved (deployed by copying files?)"
running="${BASH_REMATCH[1]}"
git -C "$REPO" cat-file -e "$running^{commit}" 2>/dev/null \
  || unknown "the checkout does not have ${running:0:7}, the commit the server runs"
git -C "$REPO" cat-file -e "$running:JS_LIBRARIES.md" 2>/dev/null \
  || not_yet "no JS_LIBRARIES.md in what production runs; the inventory step comes first"
change="$(git -C "$REPO" log -1 --format=%H "$running" -- JS_LIBRARIES.md)"

# Walk the reflog newest first while each deploy still carries the change;
# the last one that does is when production first ran it.
moved=""
while read -r sha ref; do
  [[ "$sha" =~ ^[0-9a-f]{40}$ && "$ref" =~ ^HEAD@\{([0-9]+)\}$ ]] || break
  git -C "$REPO" merge-base --is-ancestor "$change" "$sha" 2>/dev/null || break
  moved="${BASH_REMATCH[1]}"
done <<< "$reflog"
[ -n "$moved" ] || unknown "could not tell when production first ran ${change:0:7}"

out="$(python3 -I -B - "$moved" "$days" "$change" <<'PY'
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
    print(f"0 the last library change ({sha}) has run on production since {since}, {worked} working days")
else:
    print(f"1 the last library change ({sha}) has run on production only since {since}, {worked} of {need} working days")
PY
)"
case "${out%% *}" in
  0) pass "${out#* }" ;;
  *) not_yet "${out#* }" ;;
esac
