#!/usr/bin/env bash
# deploy_script REPO: the repo deploys with base.robnugen.com's
# deploy_to_dh.sh, filled in for the site's own server.
#
# Done when deploy_to_dh.sh is tracked and executable, sets DEST, DEST_PATH
# and PULL_URL, no longer says DEST="example", and no longer carries the old
# header that installs a site with rsync. When the scanner hands over the
# site's server (SITE_SERVER_SSH_ALIAS, SITE_SERVER_DIRECTORY), DEST must be
# that alias and DEST_PATH that directory or one above it: a server
# directory of x.com/wwwroot is a project root of x.com. Run by hand
# without them, the server is not compared and the line says so.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

script='deploy_to_dh.sh'
mode="$(git -C "$REPO" ls-files -s -- "$script" 2>/dev/null | cut -d' ' -f1)"
[ -n "$mode" ] || not_yet "no tracked $script; copy base.robnugen.com's"
[ "$mode" = 100755 ] || not_yet "$script is tracked but not executable: git update-index --chmod=+x $script"

body="$(git -C "$REPO" show "HEAD:$script" 2>/dev/null)" || unknown "could not read $script at HEAD"

value() {
  printf '%s\n' "$body" | sed -n -E "s/^$1=\"([^\"]*)\".*/\\1/p" | head -1
}
grep -Eq '^PULL_URL=' <<<"$body" || not_yet "$script sets no PULL_URL; it is older than base.robnugen.com's"
dest="$(value DEST)"
dest_path="$(value DEST_PATH)"
pull_url="$(value PULL_URL)"
[ -n "$dest" ] && [ -n "$dest_path" ] || not_yet "$script does not set both DEST and DEST_PATH"
[ "$dest" != example ] || not_yet "$script still says DEST=\"example\""
case "$pull_url" in
  ''|origin) ;;
  *) not_yet "$script has PULL_URL=\"$pull_url\"; it is \"origin\" or empty" ;;
esac
! grep -q 'rsync -a ./' <<<"$body" || not_yet "$script has the old rsync header; copy base.robnugen.com's"

alias="${SITE_SERVER_SSH_ALIAS:-}"
dir="${SITE_SERVER_DIRECTORY:-}"
if [ -z "$alias" ] || [ -z "$dir" ]; then
  pass "$script deploys to $dest:$dest_path (not compared with a server)"
fi
[ "$dest" = "$alias" ] || not_yet "$script has DEST=\"$dest\", but the site's server is $alias"
dir="${dir%/}"
case "$dir" in
  "$dest_path"|"$dest_path"/*) ;;
  *) not_yet "$script has DEST_PATH=\"$dest_path\", but the site's server directory is $dir" ;;
esac
pass "$script deploys to $dest:$dest_path, the site's server"
