#!/usr/bin/env bash
# memo: commit
# deploy_script REPO: the repo has a tracked, executable deploy script that
# deploys by git.
#
# The script is judged by what it does, never by its name or by how it
# spells its variables: it pushes with git, then pulls with git over a
# remote shell in a directory on a server, and copies nothing with rsync or
# scp. A script named for its site, deploy_<slug>_to_dh.sh, is the preferred
# form and is read first; deploy_to_dh.sh and any other deploy*.sh at the
# repo's root are read after it, and pass with a line naming the preferred
# form.
#
# When the scanner hands over the site's server (SITE_SERVER_SSH_ALIAS,
# SITE_SERVER_DIRECTORY), the script must deploy to that alias, in that
# directory or one above it: a server directory of x.com/wwwroot is a
# project root of x.com. Run by hand without them, the server is not
# compared and the line says so. deploy_script.py holds the reading.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

rc=0
line="$(python3 -I -B "$HERE/deploy_script.py" "$REPO")" || rc=$?
case "$rc" in
  0) pass "$line" ;;
  1) not_yet "$line" ;;
  *) unknown "${line:-could not read the deploy script}" ;;
esac
