#!/bin/bash
# Install (or update) the pinned copy of journal-sync that Carrie's run.sh runs.
#
#   tools/journal-sync/install.sh [target]     default target: ~/work/carrie/journal-sync
#
# Copies the script and the Journal classes it needs out of this checkout, so
# what runs hourly is exactly what was installed, whatever branch mg is on
# later. Re-run it deliberately to pick up a change. Records the commit it
# came from in INSTALLED_FROM.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
target="${1:-$HOME/work/carrie/journal-sync}"

mkdir -p "$target/lib"
cp "$here/journal_sync.php" "$target/journal_sync.php"
for class in EntryPath EntryCheck Files Listing Sync; do
    cp "$repo/classes/Journal/$class.php" "$target/lib/$class.php"
done
{
    echo "branch: $(git -C "$repo" rev-parse --abbrev-ref HEAD)"
    echo "commit: $(git -C "$repo" rev-parse HEAD)"
    echo "installed: $(date '+%d %b %Y %H:%M %Z')"
    if [ -n "$(git -C "$repo" status --porcelain -- tools/journal-sync classes/Journal)" ]; then
        echo "WARNING: installed with uncommitted changes"
    fi
} > "$target/INSTALLED_FROM"

php -l "$target/journal_sync.php" >/dev/null
for f in "$target"/lib/*.php; do php -l "$f" >/dev/null; done
echo "Installed journal-sync to $target"
cat "$target/INSTALLED_FROM"
