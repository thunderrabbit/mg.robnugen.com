#!/usr/bin/env bash
# Install (or update) the pinned copy of deets.sh on an EC2 server, as
# ~/.local/bin/sweep_ec2_deets_for_mg.sh, so what the sweep runs there is
# exactly what was installed, whatever branch mg is on later. Re-run it
# deliberately to pick up a change. Records the commit it came from beside it.
#
#   tools/ec2-sweep/install.sh ALIAS      ALIAS is a Host in ~/.ssh/config
set -euo pipefail

alias_="${1:?usage: install.sh ALIAS}"
[[ "$alias_" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "not an ssh alias: $alias_" >&2; exit 2; }
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
target='~/.local/bin/sweep_ec2_deets_for_mg.sh'

bash -n "$here/deets.sh"
ssh -o BatchMode=yes "$alias_" 'mkdir -p ~/.local/bin'
scp -q "$here/deets.sh" "$alias_:$target"
{
    echo "branch: $(git -C "$repo" rev-parse --abbrev-ref HEAD)"
    echo "commit: $(git -C "$repo" rev-parse HEAD)"
    echo "installed: $(date -u '+%F %T UTC')"
    if [ -n "$(git -C "$repo" status --porcelain -- tools/ec2-sweep/deets.sh)" ]; then
        echo "WARNING: installed with uncommitted changes"
    fi
} | ssh -o BatchMode=yes "$alias_" "cat > ${target%.sh}.INSTALLED_FROM && chmod +x $target && bash -n $target"
echo "Installed deets.sh on $alias_ as $target"
ssh -o BatchMode=yes "$alias_" "cat ${target%.sh}.INSTALLED_FROM"
