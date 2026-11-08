#!/usr/bin/env bash
# The facts mg's standards board needs about the hostnames this server
# serves, as one JSON object on stdout. Runs ON the server, by name, installed
# there as ~/.local/bin/sweep_ec2_deets_for_mg.sh by tools/ec2-sweep/install.sh
# and driven from Lemur 13 by tools/ec2-sweep/sweep.sh:
#
#   sweep_ec2_deets_for_mg.sh HOSTNAME=DIRECTORY [HOSTNAME=DIRECTORY ...]
#
# For each pair it reports the PHP that answers web requests for HOSTNAME, by
# asking this server for /_php_version.php over loopback through the real
# vhost: first over TLS (--resolve HOSTNAME:443:127.0.0.1), then, because TLS
# may end at a load balancer, over plain HTTP on port 80 with Host: and
# X-Forwarded-Proto: https. The first route that answers with a bare version
# wins. It also says whether DIRECTORY exists and is a git checkout, what HEAD
# is, and when the directory path itself last changed (a `current` symlink's
# own time), the clue a non-git deploy leaves.
#
# Reads only. Never writes to a repo, a docroot or a config. Needs bash, curl,
# git and python3 (Ubuntu 22.04 has all four); php is optional. Diagnostics go
# to stderr; stdout is the JSON and nothing else.
set -euo pipefail

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

# probe HOST: sets php_version, probe_route, probe_problem; notes web_server.
probe() {
  local host="$1" code rc body="$tmp/body" hdr="$tmp/hdr" problems=() route
  php_version=""; probe_route=""; probe_problem=""
  for route in https http_forwarded; do
    : > "$body"; : > "$hdr"
    if [ "$route" = https ]; then
      code="$(curl -s --max-time 10 --resolve "$host:443:127.0.0.1" -D "$hdr" -o "$body" \
                -w '%{http_code}' "https://$host/_php_version.php" 2>/dev/null)" && rc=0 || rc=$?
    else
      code="$(curl -s --max-time 10 -H "Host: $host" -H "X-Forwarded-Proto: https" -D "$hdr" -o "$body" \
                -w '%{http_code}' "http://127.0.0.1/_php_version.php" 2>/dev/null)" && rc=0 || rc=$?
    fi
    if [ -z "$web_server" ] && [ -s "$hdr" ]; then
      # A vhost may send no Server header; that is not a failure of the probe.
      web_server="$( (grep -i '^Server:' "$hdr" || true) | head -1 | cut -d' ' -f2- | tr -d '\r')"
    fi
    if [ "$rc" -ne 0 ]; then
      problems+=("curl: $rc on $route"); continue
    fi
    if [ "$code" != "200" ]; then
      problems+=("http $code on $route"); continue
    fi
    # PHP_VERSION may carry a packager's suffix (8.1.2-1ubuntu2.28); the
    # version is the numeric part at the front.
    local answer
    answer="$(tr -d '\r\n' < "$body")"
    if [[ "$answer" =~ ^([0-9]+\.[0-9]+(\.[0-9]+)?)(-[A-Za-z0-9.~+-]*)?$ ]]; then
      php_version="${BASH_REMATCH[1]}"; probe_route="$route"; return 0
    fi
    problems+=("body is not a version on $route")
  done
  probe_problem="$(IFS=,; echo "${problems[*]}")"
  probe_problem="${probe_problem//,/, }"
  return 0
}

for pair in "$@"; do
  host="${pair%%=*}"
  dir="${pair#*=}"
  [[ "$host" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || { echo "not a hostname: $host" >&2; exit 2; }
  [[ "$dir" =~ ^/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$ && "$dir" != *..* ]] || { echo "not an absolute directory: $dir" >&2; exit 2; }

  probe "$host"

  directory_exists=false; is_git_checkout=false; head_commit=""; head_branch=""; deployed_at=""
  if [ -d "$dir" ]; then
    directory_exists=true
    if git --no-optional-locks -C "$dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
      is_git_checkout=true
      head_commit="$(git --no-optional-locks -C "$dir" rev-parse HEAD 2>/dev/null || true)"
      head_branch="$(git --no-optional-locks -C "$dir" symbolic-ref --short -q HEAD 2>/dev/null || true)"
    fi
    if changed="$(stat -c %Y "$dir" 2>/dev/null)"; then
      deployed_at="$(date -u -d "@$changed" '+%F %T')"
    fi
  fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$host" "$dir" "$php_version" "$probe_route" "$(clean "$probe_problem")" \
    "$directory_exists" "$is_git_checkout" "$head_commit" "$(clean "$head_branch")" "$deployed_at" >> "$records"
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
        host, d, php, route, problem, exists, is_git, commit, branch, deployed = line.rstrip("\n").split("\t")
        domains.append({
            "domain": host, "directory": d,
            "php_version": opt(php), "probe_route": opt(route), "probe_problem": problem,
            "directory_exists": exists == "true", "is_git_checkout": is_git == "true",
            "head_commit": opt(commit), "head_branch": opt(branch), "deployed_at_utc": opt(deployed),
        })
print(json.dumps({"ssh_alias": None, "swept_at_utc": swept_at,
                  "web_server": opt(web_server), "cli_php": opt(cli_php), "domains": domains}))
PY
