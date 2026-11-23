#!/usr/bin/env bash
# Add pictures to an mg issue, from Lemur 13.
#
#   tools/issue-images/upload.sh [--dry-run] [--as=<aiu_id>] <issue_id> <file-or-dir> ...
#
# A directory adds the pictures directly in it (not its subdirectories). Only
# .png .jpg .jpeg .gif .webp files are sent; anything else is named and
# skipped. The files are copied to a temporary directory on the server, which
# tools/issue_images.php reads, checks and stores from, and which is removed
# again whatever happens. Start with --dry-run: every check runs, nothing is
# stored.
#
# Prints a line per file, then the markdown line of each picture, ready to
# paste into an issue. Uploading the same files again stores nothing new.
# Without --as the uploader is the human of the account that owns the issue.
# Exit 1 when a file was refused, or a picture was skipped for its name.
set -euo pipefail

usage() { echo "usage: upload.sh [--dry-run] [--as=<aiu_id>] <issue_id> <file-or-dir> ..." >&2; exit 2; }

flags=()
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) flags+=("--dry-run"); shift ;;
    --as=*) [[ "${1#--as=}" =~ ^[1-9][0-9]*$ ]] || usage; flags+=("$1"); shift ;;
    --*) usage ;;
    *) break ;;
  esac
done
[ $# -ge 2 ] || usage
issue="$1"; shift
[[ "$issue" =~ ^[1-9][0-9]*$ ]] || usage

files=()
names=" "
left_out=0
add() {
  local file="$1" base
  base="$(basename "$file")"
  case "${base,,}" in
    *.png|*.jpg|*.jpeg|*.gif|*.webp) ;;
    *) echo "skipped        $file  (not a .png .jpg .jpeg .gif .webp file)" >&2; return ;;
  esac
  case "$base" in
    *[!A-Za-z0-9._\ \(\)-]*|-*)
      echo "skipped        $file  (rename it: letters, digits, space, . _ - only)" >&2
      left_out=1; return ;;
  esac
  case "$names" in
    *"/$base/"*) echo "two files are both named $base; send them separately" >&2; exit 2 ;;
  esac
  names+="/$base/ "
  files+=("$file")
}
for input in "$@"; do
  if [ -d "$input" ]; then
    while IFS= read -r -d '' file; do add "$file"; done \
      < <(find "$input" -maxdepth 1 -type f -print0 | sort -z)
  elif [ -f "$input" ]; then
    add "$input"
  else
    echo "$input: no such file or directory" >&2; exit 2
  fi
done
[ ${#files[@]} -gt 0 ] || { echo "no pictures to send" >&2; exit 2; }

remote="$(ssh mg 'mktemp -d "${TMPDIR:-/tmp}/mg-issue-images.XXXXXXXX"')"
[[ "$remote" =~ ^/[A-Za-z0-9._/-]+/mg-issue-images\.[A-Za-z0-9]{8}$ ]] \
  || { echo "unexpected temporary directory from the server: $remote" >&2; exit 1; }
trap 'ssh mg "rm -rf -- $remote"' EXIT

scp -q -- "${files[@]}" "mg:$remote/"
status=0
ssh mg "/usr/local/php85/bin/php ~/mg.robnugen.com/tools/issue_images.php import --issue=$issue --dir=$remote ${flags[*]}" \
  || status=$?
# A picture left behind for its name is a picture missing from the issue.
[ "$status" -ne 0 ] || [ "$left_out" -eq 0 ] || status=1
exit "$status"
