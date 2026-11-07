#!/usr/bin/env bash
# js_hotfixes_ported REPO: every commit on the line the server runs is also
# on the branch the site is preparing (SITE_UPGRADE_BRANCH, from mg), by
# patch, by a "(cherry picked from commit X)" note or "<Line>-has: X"
# trailer naming a commit on that branch, or by author date and subject.
# BEGIN commits and merges are not ported. A site with no upgrade branch has
# nothing to port and passes.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

line="${SITE_UPGRADE_BRANCH:-}"
[ -n "$line" ] || pass "the site has one line; there is nothing to port"
server="${SITE_SERVER_COMMIT:-}"
[[ "$server" =~ ^[0-9a-f]{40}$ ]] || unknown "the server was not asked what it runs"
[[ "$line" =~ ^[A-Za-z0-9._/-]{1,100}$ && "$line" != *..* ]] || unknown "the upgrade branch's name is not one this check will use"
git -C "$REPO" rev-parse -q --verify "refs/heads/$line^{commit}" >/dev/null 2>&1 \
  || unknown "this checkout has no branch named $line"
git -C "$REPO" cat-file -e "$server^{commit}" 2>/dev/null \
  || unknown "this checkout does not have ${server:0:7}, which the server runs; fetch first"

out="$(python3 -I -B - "$REPO" "$line" "$server" <<'PY'
import re, subprocess, sys
repo, line, server = sys.argv[1:4]


def git(*args):
    done = subprocess.run(["git", "--no-optional-locks", "-C", repo, *args], capture_output=True, text=True)
    if done.returncode != 0:
        print(f"x git {args[0]} failed: {done.stderr.strip()}")
        raise SystemExit(2)
    return done.stdout


def on_line(sha):
    return subprocess.run(["git", "--no-optional-locks", "-C", repo, "merge-base", "--is-ancestor", sha, line],
                          capture_output=True).returncode == 0


keys = set()
for row in git("log", "--no-merges", "--format=%at%x09%s", f"{server}..{line}").splitlines():
    keys.add(row)
missing = []
for sha in git("rev-list", "--no-merges", "--cherry-pick", "--right-only", f"{line}...{server}").split():
    at, subject, body = git("show", "-s", "--format=%at%n%s%n%b", sha).split("\n", 2)
    if subject.startswith("BEGIN "):
        continue
    if f"{at}\t{subject}" in keys:
        continue
    cited = re.findall(r"\(cherry picked from commit ([0-9a-f]{7,40})\)", body)
    cited += re.findall(r"^[A-Za-z]+-has: *([0-9a-f]{7,40})", body, re.M)
    if any(on_line(c) for c in cited):
        continue
    missing.append(f"{sha[:7]} {subject}")
if not missing:
    print(f"every commit the server runs is on {line}")
    raise SystemExit(0)
before = f"{len(missing)} commit(s) on the server's line are not on {line}: "
room = 255 - len(before)
text = ", ".join(missing)
if len(text) > room:
    cut = text[:max(room - 3, 0)]
    text = (cut.rsplit(", ", 1)[0] + ", ...") if ", " in cut else (cut + "...")
print(before + text)
raise SystemExit(1)
PY
)"
case $? in
  0) pass "$out" ;;
  1) not_yet "$out" ;;
  *) unknown "${out#x }" ;;
esac
