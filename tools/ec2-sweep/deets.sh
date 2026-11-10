#!/usr/bin/env bash
# The facts mg's standards board needs about the hostnames this server
# serves, as one JSON object on stdout. Runs ON the server, by name, installed
# there as ~/.local/bin/sweep_ec2_deets_for_mg.sh by tools/ec2-sweep/install.sh
# and driven from Lemur 13 by tools/ec2-sweep/sweep.sh:
#
#   sweep_ec2_deets_for_mg.sh HOSTNAME=DIRECTORY [HOSTNAME=DIRECTORY ...]
#
# The server's web PHP is read from the box, not by asking a site: the one
# `php*.load` module enabled in /etc/apache2/mods-enabled names the PHP, and
# dpkg says which release of that module's package is installed. One read
# covers every hostname, since they share the web server. A server that runs
# PHP some other way (php-fpm) gets no version and a plain problem line.
# For each pair it also says whether DIRECTORY exists and is a git checkout,
# what HEAD is, its reflog (up to 200 entries of commit and unix time, for the
# soak check), and when the directory path itself last changed (a `current`
# symlink's own time), the clue a non-git deploy leaves. git is told
# safe.directory='*' for its own reads, since a production directory may be
# owned by the web user and the sweeping user only in its group.
#
# Reads only. Never writes to a repo, a docroot or a config. Needs bash, git
# and python3 (Ubuntu 22.04 has all three); apache2 and php are optional. Diagnostics go
# to stderr; stdout is the JSON and nothing else.
set -euo pipefail

# Run as an ssh forced command (command="..." in authorized_keys), the client's
# pairs arrive in SSH_ORIGINAL_COMMAND instead of as arguments. They are
# split on whitespace and checked below like any argument, so the key can ask
# about nothing but a hostname and an absolute directory.
if [ $# -eq 0 ] && [ -n "${SSH_ORIGINAL_COMMAND:-}" ]; then
  read -r -a from_ssh <<< "$SSH_ORIGINAL_COMMAND"
  set -- "${from_ssh[@]}"
fi

[ $# -ge 1 ] || { echo "usage: $0 HOSTNAME=DIRECTORY [HOSTNAME=DIRECTORY ...]" >&2; exit 2; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
records="$tmp/records.tsv"
: > "$records"
web_server=""

# One line of text fit for a JSON string and the page: no tabs or newlines,
# at most 255 characters.
clean() {
  local s="${1//$'\t'/ }"
  s="${s//$'\n'/ }"
  printf '%s' "${s:0:255}"
}

# web_php: sets php_version and probe_problem from the enabled Apache PHP
# module and its package. The version is the numeric part of the package's
# (8.1.2-1ubuntu2.20 gives 8.1.2); the packager's suffix is not kept.
web_php() {
  php_version=""; probe_problem=""
  local loads=() f pkg ver
  for f in /etc/apache2/mods-enabled/php*.load; do
    [ -e "$f" ] && loads+=("$f")
  done
  if [ "${#loads[@]}" -eq 0 ]; then
    probe_problem="no php module enabled in /etc/apache2/mods-enabled"; return 0
  fi
  if [ "${#loads[@]}" -gt 1 ]; then
    probe_problem="more than one php module enabled"; return 0
  fi
  f="${loads[0]##*/}"; f="${f%.load}"
  pkg="libapache2-mod-$f"
  ver="$(dpkg-query -W -f='${Version}' "$pkg" 2>/dev/null || true)"
  ver="${ver#*:}"
  if [[ "$ver" =~ ^([0-9]+\.[0-9]+(\.[0-9]+)?) ]]; then
    php_version="${BASH_REMATCH[1]}"
  else
    probe_problem="dpkg has no version for $pkg"
  fi
}

web_php
web_server="$( (apache2 -v 2>/dev/null || true) | sed -n 's/^Server version: *//p' | head -1)"

for pair in "$@"; do
  host="${pair%%=*}"
  dir="${pair#*=}"
  [[ "$host" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || { echo "not a hostname: $host" >&2; exit 2; }
  [[ "$dir" =~ ^/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$ && "$dir" != *..* ]] || { echo "not an absolute directory: $dir" >&2; exit 2; }

  directory_exists=false; is_git_checkout=false; head_commit=""; head_branch=""; deployed_at=""; reflog=""
  if [ -d "$dir" ]; then
    directory_exists=true
    if git -c safe.directory="*" --no-optional-locks -C "$dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
      is_git_checkout=true
      head_commit="$(git -c safe.directory="*" --no-optional-locks -C "$dir" rev-parse HEAD 2>/dev/null || true)"
      head_branch="$(git -c safe.directory="*" --no-optional-locks -C "$dir" symbolic-ref --short -q HEAD 2>/dev/null || true)"
      # "commit:unixtime" pairs, newest first, comma-separated.
      reflog="$( (git -c safe.directory="*" --no-optional-locks -C "$dir" reflog show --date=unix --format='%H %gd' -200 HEAD 2>/dev/null || true) \
                 | sed -n 's/^\([0-9a-f]\{40\}\) HEAD@{\([0-9]\{1,12\}\)}$/\1:\2/p' | paste -sd, -)"
    fi
    if changed="$(stat -c %Y "$dir" 2>/dev/null)"; then
      deployed_at="$(date -u -d "@$changed" '+%F %T')"
    fi
  fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$host" "$dir" "$php_version" "" "$(clean "$probe_problem")" \
    "$directory_exists" "$is_git_checkout" "$head_commit" "$(clean "$head_branch")" "$deployed_at" "$reflog" >> "$records"
  if [ -n "$head_commit" ]; then at="HEAD ${head_commit:0:7}"; else at="no git checkout"; fi
  echo "$host: ${php_version:-no version (${probe_problem})}; $at" >&2
done

cli_php="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, ".", PHP_RELEASE_VERSION;' 2>/dev/null || true)"
[[ "$cli_php" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || cli_php=""

python3 -I -B - "$records" "$(date -u '+%F %T')" "$(clean "$web_server")" "$cli_php" <<'PY'
import json, sys
records, swept_at, web_server, cli_php = sys.argv[1:5]
def opt(s): return s if s != "" else None
domains = []
with open(records, encoding="utf-8") as f:
    for line in f:
        host, d, php, route, problem, exists, is_git, commit, branch, deployed, reflog = line.rstrip("\n").split("\t")
        domains.append({
            "domain": host, "directory": d,
            "php_version": opt(php), "probe_route": opt(route), "probe_problem": problem,
            "directory_exists": exists == "true", "is_git_checkout": is_git == "true",
            "head_commit": opt(commit), "head_branch": opt(branch), "deployed_at_utc": opt(deployed),
            "reflog": [{"commit": c, "unix": int(t)} for c, t in (e.split(":") for e in reflog.split(",") if e)],
        })
print(json.dumps({"ssh_alias": None, "swept_at_utc": swept_at,
                  "web_server": opt(web_server), "cli_php": opt(cli_php), "domains": domains}))
PY
