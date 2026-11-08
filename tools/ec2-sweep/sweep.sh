#!/usr/bin/env bash
# Sweep the EC2 servers mg knows, from Lemur 13: ask mg for the servers and
# their hostnames, run the pinned deets.sh on each server over ssh, and stream
# each answer to tools/import_ec2_sweep.php on mg. Nothing schedules this yet;
# Rob runs it, as he runs the DreamHost sweep.
#
#   tools/ec2-sweep/sweep.sh [--dry-run] [alias ...]
#
# A server that does not answer is reported and skipped; the others still
# import. Exits 1 if any server failed. --dry-run imports inside a transaction
# that is rolled back, so the summary shows what a real run would do.
#
# PHP version: if the machine has an ssh host named <alias>_php_version (a
# forced-command key that only prints the web PHP's package version), its
# answer wins over deets.sh's own read of the Apache package. That host's
# stderr is noisy (PTY and shared-connection lines), so only a stdout line
# that starts with a digit counts, and only its numeric part is kept.
#
# Overrides for testing: SWEEP_SSH (default ssh -o BatchMode=yes
# -o ConnectTimeout=15), SWEEP_SITE_CMD (default site.sh beside the scanner),
# SWEEP_IMPORT_CMD (default the importer over ssh mg).
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dry_run=""
only=()
for arg in "$@"; do
  case "$arg" in
    --dry-run) dry_run="--dry-run" ;;
    -*) echo "Unknown option: $arg. Usage: sweep.sh [--dry-run] [alias ...]" >&2; exit 2 ;;
    *) only+=("$arg") ;;
  esac
done

site_cmd="${SWEEP_SITE_CMD:-$here/../site-standards/site.sh}"
import_cmd="${SWEEP_IMPORT_CMD:-ssh mg '/usr/local/php85/bin/php ~/mg.robnugen.com/tools/import_ec2_sweep.php'}"
[ -z "$dry_run" ] || import_cmd+=" --dry-run"
read -r -a ssh_cmd <<< "${SWEEP_SSH:-ssh -o BatchMode=yes -o ConnectTimeout=15}"

servers="$($site_cmd ec2-servers)" || { echo "Could not get the server list from mg." >&2; exit 1; }
# One line per server: alias, then hostname=directory pairs, tab-separated.
lines="$(python3 -I -B -c '
import json, sys
for s in json.load(sys.stdin):
    print("\t".join([s["ssh_alias"], *(d["domain"] + "=" + d["directory"] for d in s["domains"])]))
' <<< "$servers")"

failed=0
seen=0
while IFS=$'\t' read -r alias_ pairs_tsv; do
  [ -n "$alias_" ] || continue
  if [ ${#only[@]} -gt 0 ]; then
    printf '%s\n' "${only[@]}" | grep -qxF -- "$alias_" || continue
  fi
  seen=$((seen + 1))
  IFS=$'\t' read -r -a pairs <<< "$pairs_tsv"
  if [ ${#pairs[@]} -eq 0 ]; then
    echo "$alias_: no hostnames entered; nothing to sweep" >&2; continue
  fi
  echo "Sweeping $alias_ (${#pairs[@]} hostname(s))..." >&2
  if ! answer="$("${ssh_cmd[@]}" "$alias_" '~/.local/bin/sweep_ec2_deets_for_mg.sh' "${pairs[@]}")"; then
    echo "$alias_: the server did not answer; is deets.sh installed there (tools/ec2-sweep/install.sh $alias_)?" >&2
    failed=1; continue
  fi
  # The forced-command host, when there is one, names the web PHP; no answer
  # (no such host, a refusal, only noise) leaves deets.sh's own reading alone.
  forced_php="$( ("${ssh_cmd[@]}" -T "${alias_}_php_version" 2>/dev/null </dev/null || true) \
                 | grep -m1 -E '^[0-9]+\.[0-9]+' | tr -d '\r' || true)"
  # The server does not know its own alias on this machine; mg needs it.
  if ! document="$(python3 -I -B -c '
import json, re, sys
d = json.load(sys.stdin)
if not isinstance(d, dict): raise SystemExit("not an object")
d["ssh_alias"] = sys.argv[1]
m = re.match(r"\d+\.\d+(\.\d+)?", sys.argv[2])
if m:
    for dom in d.get("domains", []):
        dom["php_version"] = m.group(0); dom["probe_route"] = None; dom["probe_problem"] = ""
print(json.dumps(d))
' "$alias_" "$forced_php" <<< "$answer" 2>/dev/null)"; then
    echo "$alias_: the server's answer was not a sweep document; is deets.sh installed there cleanly (tools/ec2-sweep/install.sh $alias_)?" >&2
    failed=1; continue
  fi
  if ! bash -c "$import_cmd" <<< "$document"; then
    failed=1
  fi
done <<< "$lines"

if [ ${#only[@]} -gt 0 ] && [ "$seen" -lt ${#only[@]} ]; then
  echo "Not every alias named is a server mg knows; see site.sh ec2-servers." >&2; failed=1
fi
exit $failed
