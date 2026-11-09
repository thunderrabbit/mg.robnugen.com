"""Remember a check's pass or fail for one site at one commit.

Used by scan.py. Standard library only. A check opts in with a line
`# memo: commit` in its first 20 lines; every other check runs on every scan.

A remembered answer is reused only when the whole key is unchanged: the site's
commit, the check script, lib.sh, the helper files beside the checks, the
step's param, every SITE_* fact the check is given, and the tools it runs
(the standards-mcp runner scripts and the local standards-* docker images).
Only `pass` and `fail` are stored, never "could not tell". One JSON file per
site lives under the XDG state directory, outside every repo, and holds only
entries for the commit last scanned.
"""

import datetime
import glob
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile

MEMO_HEADER = "# memo: commit"
HEADER_LINES = 20
REMEMBERED = ("pass", "fail")
SHORTCODE = re.compile(r"\A[a-z0-9]+(?:-[a-z0-9]+)*\Z")
DOCKER_TIMEOUT = 20


def is_memoizable(script_path):
    """Whether the script says, in its first 20 lines, that its answer depends only on what the key covers."""
    try:
        with open(script_path, encoding="utf-8", errors="replace") as handle:
            for _, line in zip(range(HEADER_LINES), handle):
                if line.rstrip("\r\n") == MEMO_HEADER:
                    return True
    except OSError:
        pass
    return False


def _feed(digest, label, data):
    """Add one labelled part, length-prefixed so parts cannot run into each other."""
    if isinstance(data, str):
        data = data.encode("utf-8")
    digest.update(f"{label}:{len(data)}:".encode("ascii"))
    digest.update(data)


def _file_bytes(path):
    with open(path, "rb") as handle:
        return handle.read()


def tool_identity(standards_mcp):
    """One hash for the tools checks run through: the runner scripts and the local standards-* docker images."""
    digest = hashlib.sha256()
    for path in sorted(glob.glob(os.path.join(standards_mcp, "run-*.sh"))):
        _feed(digest, "runner:" + os.path.basename(path), _file_bytes(path))
    try:
        listed = subprocess.run(
            ["docker", "images", "--no-trunc", "--format", "{{.Repository}}:{{.Tag}} {{.ID}}"],
            capture_output=True, text=True, timeout=DOCKER_TIMEOUT, stdin=subprocess.DEVNULL)
        if listed.returncode != 0:
            raise OSError(listed.stderr)
        images = sorted(line for line in listed.stdout.splitlines() if line.startswith("standards-"))
    except (OSError, subprocess.SubprocessError):
        images = ["no-docker"]
    _feed(digest, "images", "\n".join(images))
    return digest.hexdigest()


def check_key(*, commit, script, lib, helpers, param, facts, tool_id):
    """The hex key a remembered answer is filed under."""
    digest = hashlib.sha256()
    _feed(digest, "commit", commit)
    _feed(digest, "script", _file_bytes(script))
    _feed(digest, "lib", _file_bytes(lib))
    for path in sorted(helpers, key=os.path.basename):
        _feed(digest, "helper:" + os.path.basename(path), _file_bytes(path))
    _feed(digest, "param", param)
    for name in sorted(facts):
        _feed(digest, "fact:" + name, facts[name] or "")
    _feed(digest, "tools", tool_id)
    return digest.hexdigest()


def memo_path(shortcode, state_home=None):
    """Where a site's memo lives."""
    if not SHORTCODE.match(shortcode):
        raise ValueError(f"not a plain site shortcode: {shortcode!r}")
    if state_home is None:
        state_home = os.environ.get("XDG_STATE_HOME") or os.path.join(os.path.expanduser("~"), ".local", "state")
    return os.path.join(state_home, "site-standards", f"memo-{shortcode}.json")


def _now_utc():
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def remembered_label(at_utc):
    """`YYYY-MM-DD HH:MM TZ` in local time for a stored UTC timestamp."""
    moment = datetime.datetime.strptime(at_utc, "%Y-%m-%d %H:%M:%S").replace(tzinfo=datetime.timezone.utc)
    return moment.astimezone().strftime("%Y-%m-%d %H:%M %Z")


class Memo:
    """One site's remembered answers. A missing or damaged file is simply empty."""

    def __init__(self, path):
        self.path = path
        self.entries = {}
        try:
            with open(path, encoding="utf-8") as handle:
                loaded = json.load(handle)
        except (OSError, ValueError):
            return
        if isinstance(loaded, dict):
            self.entries = {key: entry for key, entry in loaded.items() if self._sound(entry)}

    @staticmethod
    def _sound(entry):
        return (isinstance(entry, dict) and entry.get("outcome") in REMEMBERED
                and all(isinstance(entry.get(field), str) for field in ("commit", "detail", "at_utc")))

    def lookup(self, key):
        entry = self.entries.get(key)
        if entry is None:
            return None
        return {"outcome": entry["outcome"], "detail": entry["detail"], "at_utc": entry["at_utc"]}

    def record(self, key, commit, outcome, detail):
        if outcome not in REMEMBERED:
            return
        self.entries[key] = {"commit": commit, "outcome": outcome, "detail": detail, "at_utc": _now_utc()}

    def save(self, current_commit):
        """Write the entries for `current_commit`. False, with one stderr line, if the file cannot be written."""
        kept = {key: entry for key, entry in self.entries.items() if entry["commit"] == current_commit}
        directory = os.path.dirname(self.path)
        temp = None
        try:
            os.makedirs(directory, exist_ok=True)
            handle, temp = tempfile.mkstemp(dir=directory, prefix=".memo-", suffix=".tmp")
            with os.fdopen(handle, "w", encoding="utf-8") as out:
                json.dump(kept, out, sort_keys=True)
            os.replace(temp, self.path)
            self.entries = kept
            return True
        except OSError as problem:
            print(f"  could not save the scan memo ({problem.strerror or problem}); the next scan runs everything",
                  file=sys.stderr, flush=True)
            if temp is not None:
                try:
                    os.unlink(temp)
                except OSError:
                    pass
            return False
