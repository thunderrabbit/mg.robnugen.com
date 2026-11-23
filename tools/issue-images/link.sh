#!/usr/bin/env bash
# Show a stored picture on another mg issue as well, from Lemur 13.
#
#   tools/issue-images/link.sh <issue_image_id> <issue_id> [--as=<aiu_id>]
#
# To find a picture's id:
#   ssh mg "mysql mgrnc -e 'SELECT issue_image_id, home_issue_id, filename FROM issue_images'"
# Without --as it acts as the human of the account that owns the issue. The
# actor must be able to write the issue, and to read an issue the picture is already on
# (Issues\Images\ImageService).
set -euo pipefail

usage() { echo "usage: link.sh <issue_image_id> <issue_id> [--as=<aiu_id>]" >&2; exit 2; }

[ $# -ge 2 ] && [ $# -le 3 ] || usage
[[ "$1" =~ ^[1-9][0-9]*$ ]] && [[ "$2" =~ ^[1-9][0-9]*$ ]] || usage
as=""
if [ $# -eq 3 ]; then
  [[ "$3" =~ ^--as=[1-9][0-9]*$ ]] || usage
  as=" $3"
fi
exec ssh mg "/usr/local/php85/bin/php ~/mg.robnugen.com/tools/issue_images.php link --image=$1 --issue=$2$as"
