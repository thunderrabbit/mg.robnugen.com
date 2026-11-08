#!/usr/bin/env bash
# Import one DreamHost sweep into mg (mg #775), from Rob's laptop.
#
#   tools/import-dh-sweep.sh ~/work/rob/dreamhost-inventory/sweeps/2026-11-09-0845 [--dry-run]
#
# Bundles the six JSON files into one document and streams it over ssh to
# tools/import_dh_sweep.php on the server, which reads it from STDIN. The sweep
# stays out of mg's repo and is never written to the server's disk. Start with
# --dry-run: it runs the whole import in a transaction and rolls it back.
#
# The directory is named YYYY-MM-DD-HHMM, the local time the sweep started
# (YYYY-MM-DD alone before 2026-11-09); the date part is the sweep's swept_on.
set -euo pipefail

dir="${1:?usage: import-dh-sweep.sh SWEEP_DIR [--dry-run]}"
flag="${2:-}"
[ -z "$flag" ] || [ "$flag" = "--dry-run" ] || { echo "unknown option: $flag" >&2; exit 2; }

python3 -I -c '
import json, os, re, sys
d = os.path.abspath(sys.argv[1])
name = os.path.basename(d)
m = re.fullmatch(r"(\d{4}-\d{2}-\d{2})(-\d{4})?", name)
if not m:
    sys.exit(f"{name}: a sweep directory is named YYYY-MM-DD or YYYY-MM-DD-HHMM")
files = {n: json.load(open(os.path.join(d, n + ".json")))
         for n in ("domains", "users", "registrations", "databases", "crons", "buckets")}
print(json.dumps({"swept_on": m.group(1), "source_dir": "sweeps/" + name, "files": files}))
' "$dir" | ssh mg "/usr/local/php85/bin/php ~/mg.robnugen.com/tools/import_dh_sweep.php $flag"
