#!/usr/bin/env bash
# Delete, on the server, every stored picture that no mg issue shows any more
# (each was removed from its last issue). From Lemur 13.
#
#   tools/issue-images/purge.sh [--dry-run]
#
# This is the only thing that ever deletes a picture's file. Start with
# --dry-run, which only lists them.
set -euo pipefail

flag="${1:-}"
[ $# -le 1 ] && { [ -z "$flag" ] || [ "$flag" = "--dry-run" ]; } \
  || { echo "usage: purge.sh [--dry-run]" >&2; exit 2; }
exec ssh mg "/usr/local/php85/bin/php ~/mg.robnugen.com/tools/issue_images.php purge $flag"
