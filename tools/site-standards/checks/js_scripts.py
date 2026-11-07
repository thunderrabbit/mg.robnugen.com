"""What JavaScript a site's checkout loads. Shared by the js_* checks; not a check.

Each js_* check runs Python with `python3 -I`, puts this directory on its path
and calls read(repo). Standard library only, and read-only: it lists the
repo's tracked files with git and reads them, nothing else.

What it finds, in the tracked .php, .phtml, .html, .htm, .js and .mjs files
outside vendor/, node_modules/, Tests/ and tests/ (other people's code and
test fixtures), and not minified:

- every <script src>, with whether it has integrity and crossorigin;
- every import-map entry, with whether the map gives it an integrity hash;
- every import that is not relative (`import x from "three"`, a URL, and
  dynamic `import("...")`), in .js/.mjs files and inline module scripts;
- how many inline <script> blocks there are;
- every script named inside PHP: a `<script src>` that PHP writes part of
  (`src="<?= $base ?>/js/forms.js"`) keeps the fixed tail after the last PHP
  block, and a PHP string that ends in `.js` or `.mjs` (`addScript('/forms/
  forms.js')`) is that tail. Each tail is resolved to the one tracked file
  whose path ends with it, when there is exactly one.

PHP blocks are blanked before reading, so `src="<?= $x ?>"` shows as dynamic
rather than being misread; what PHP writes is then looked for in the PHP
itself, as above. A src built from several PHP strings is not seen.
"""

import json
import os
import re
import subprocess

EXTENSIONS = (".php", ".phtml", ".html", ".htm", ".js", ".mjs")
SKIP_DIRS = {"vendor", "node_modules", "Tests", "tests", ".git"}
PHP_BLOCK = re.compile(r"<\?(?:php\b|=)?.*?(?:\?>|\Z)", re.S)
DYNAMIC = "\x01"
SCRIPT = re.compile(r"<script\b([^>]*)>(.*?)</script\s*>", re.S | re.I)
ATTR = re.compile(r"([A-Za-z_:][-A-Za-z0-9_:.]*)(?:\s*=\s*(\"[^\"]*\"|'[^']*'|[^\s\"'>]+))?")
IMPORT = re.compile(
    r"""(?:\bimport\s+(?:[\w*{}\s,$]+\s+from\s+)?|\bexport\s+[\w*{}\s,$]+\s+from\s+|\bimport\s*\(\s*)["']([^"'\n]+)["']"""
)
VERSION = re.compile(r"(?<![0-9.])v?\d+\.\d+\.\d+(?![0-9])")
EXTERNAL = re.compile(r"\A(?:https?:)?//", re.I)
# A PHP string that names a script: quoted, one path, ending in .js or .mjs.
# No backslash: that is a regular expression (`'request\.js'`), not a path.
PHP_SCRIPT_STRING = re.compile(r"""(["'])((?:(?!\1)[^\s<>()\\])+\.m?js)\1""")


def blank_php(text):
    """The text with each PHP block replaced by DYNAMIC marks, newlines kept."""
    return PHP_BLOCK.sub(lambda m: re.sub(r"[^\n]", DYNAMIC, m.group(0)), text)


def tracked_files(repo, rev=None, minified=False, skip=SKIP_DIRS):
    """The files read(), as git knows them: in the index, or at commit `rev`.

    Minified files are left out unless asked for: they are read for nothing,
    but a copy of a library is often one. `skip` names the directories left
    out; a copy may well sit under vendor/, so a caller resolving names skips
    less.
    """
    listing = ["ls-files", "-z"] if rev is None else ["ls-tree", "-r", "-z", "--name-only", rev]
    done = subprocess.run(["git", "--no-optional-locks", "-C", repo, *listing],
                          capture_output=True, text=True)
    if done.returncode != 0:
        raise RuntimeError("could not list the checkout's files with git")
    for path in done.stdout.split("\0"):
        if not path.endswith(EXTENSIONS):
            continue
        if not minified and path.endswith((".min.js", ".min.mjs")):
            continue
        if skip.intersection(path.split("/")[:-1]):
            continue
        yield path


def attributes(text):
    found = {}
    for name, value in ATTR.findall(text):
        if value[:1] in ('"', "'"):
            value = value[1:-1]
        found.setdefault(name.lower(), value)
    return found


def line_of(text, offset):
    return text.count("\n", 0, offset) + 1


def is_external(url):
    return bool(EXTERNAL.match(url))


def has_version(url):
    return bool(VERSION.search(url))


def imports_in(text, path, base_line, into):
    for m in IMPORT.finditer(text):
        spec = m.group(1)
        if spec.startswith(("./", "../", "/")) and not spec.startswith("//"):
            continue
        into.append({"spec": spec, "file": path, "line": base_line + text.count("\n", 0, m.start()),
                     "dynamic": DYNAMIC in spec})


def contents(repo, path, rev=None):
    """A file's text in the checkout, or at commit `rev`; None if unreadable."""
    if rev is not None:
        done = subprocess.run(["git", "--no-optional-locks", "-C", repo, "show", f"{rev}:{path}"],
                              capture_output=True)
        return done.stdout.decode("utf-8", "replace") if done.returncode == 0 else None
    try:
        with open(os.path.join(repo, path), encoding="utf-8", errors="replace") as f:
            return f.read()
    except OSError:
        return None


def read(repo, rev=None):
    """Everything the checks need to know about the repo's scripts, as a dict.

    With `rev`, the scripts as they were at that commit.
    """
    found = {"scripts": [], "importmap": [], "imports": [], "inline": [], "named": [], "problems": []}
    tails = []
    for path in tracked_files(repo, rev):
        raw = contents(repo, path, rev)
        if raw is None:
            continue
        if path.endswith((".js", ".mjs")):
            imports_in(raw, path, 1, found["imports"])
            continue
        is_php = path.endswith((".php", ".phtml"))
        text = blank_php(raw) if is_php else raw
        if is_php:
            php_named_in(raw, text, path, tails)
        for m in SCRIPT.finditer(text):
            attrs = attributes(m.group(1))
            line = line_of(text, m.start())
            body = m.group(2)
            kind = attrs.get("type", "").strip().lower()
            if "src" in attrs:
                src = attrs["src"].strip()
                dynamic = DYNAMIC in src or src == ""
                found["scripts"].append({
                    "src": src, "file": path, "line": line, "dynamic": dynamic,
                    "integrity": attrs.get("integrity", "").strip().startswith(("sha256-", "sha384-", "sha512-")),
                    "crossorigin": "crossorigin" in attrs,
                })
                tail = src.rsplit(DYNAMIC, 1)[-1] if dynamic else ""
                if tail.endswith((".js", ".mjs")):
                    tails.append({"tail": tail, "file": path, "line": line})
            elif kind == "importmap":
                read_importmap(body, path, line, found)
            elif body.strip():
                found["inline"].append({"file": path, "line": line})
                if kind == "module":
                    imports_in(body, path, line, found["imports"])
    found["named"] = resolve_tails(repo, rev, tails)
    return found


def php_named_in(raw, blanked, path, into):
    """Script paths written as PHP strings: read where the blanked text has PHP."""
    for m in PHP_SCRIPT_STRING.finditer(raw):
        if blanked[m.start()] != DYNAMIC:
            continue  # a string in HTML or in an inline script, not in PHP
        into.append({"tail": m.group(2), "file": path, "line": line_of(raw, m.start())})


def resolve_tails(repo, rev, tails):
    """Each PHP-named tail with the tracked file it names, or None if not exactly one matches.

    An external URL is kept as it is: it names itself.
    """
    if not tails:
        return []
    files = [f for f in tracked_files(repo, rev, minified=True, skip={".git"})
             if f.endswith((".js", ".mjs"))]
    named = []
    for item in tails:
        tail = item["tail"]
        if is_external(tail):
            named.append({**item, "path": tail})
            continue
        want = tail.lstrip("/")
        matches = [f for f in files if f == want or f.endswith("/" + want)]
        named.append({**item, "path": matches[0] if len(matches) == 1 else None})
    return named


def read_importmap(body, path, line, found):
    if DYNAMIC in body:
        found["problems"].append(f"the import map at {path}:{line} is written by PHP, so it can't be read")
        return
    try:
        doc = json.loads(body)
    except ValueError:
        found["problems"].append(f"the import map at {path}:{line} is not valid JSON")
        return
    hashes = doc.get("integrity") if isinstance(doc.get("integrity"), dict) else {}
    maps = [doc.get("imports")] + list((doc.get("scopes") or {}).values())
    for mapping in maps:
        if not isinstance(mapping, dict):
            continue
        for key, url in mapping.items():
            if not isinstance(url, str):
                continue
            found["importmap"].append({
                "key": key, "url": url, "file": path, "line": line,
                "prefix": key.endswith("/"),
                "integrity": str(hashes.get(url, "")).startswith(("sha256-", "sha384-", "sha512-")),
            })


def where(item):
    return f"{item['file']}:{item['line']}"


def loaded_urls(found):
    """Every script the site loads: <script src>s, import-map targets, and the
    copies PHP names (as tracked paths)."""
    urls = [s["src"] for s in found["scripts"] if not s["dynamic"]]
    urls += [e["url"] for e in found["importmap"]]
    urls += [i["spec"] for i in found["imports"] if is_external(i["spec"]) and not i["dynamic"]]
    urls += [n["path"] for n in found["named"] if n["path"] is not None]
    return urls


def dependencies(repo, rev=None):
    """package.json's `dependencies` (name -> range), or {} without one."""
    text = contents(repo, "package.json", rev)
    if text is None:
        return {}
    deps = json.loads(text).get("dependencies") or {}
    return {str(k): str(v) for k, v in deps.items()} if isinstance(deps, dict) else {}


def libraries(repo, rev=None):
    """What the site loads from elsewhere, as a sorted list that two commits can be compared by."""
    found = read(repo, rev)
    urls = [u for u in loaded_urls(found) if is_external(u) or has_version(u)]
    urls += [i["spec"] for i in found["imports"] if not is_external(i["spec"]) and not i["dynamic"]]
    urls += [f"npm:{k}@{v}" for k, v in dependencies(repo, rev).items()]
    return sorted(set(urls))


MONTHS = "jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec"
DATED_TEST = re.compile(rf"\Atesting-js-since-\d{{4}}-(?:{MONTHS})-\d{{2}}\.(sh|js)\Z")
TESTS_LINE = re.compile(r"^JS tests:[ \t]*(\S.*?)[ \t]*$", re.M)
STRICT_LINE = re.compile(r"^JS strict tests:[ \t]*(\S.*?)[ \t]*$", re.M)


def smoke(repo):
    """How the site's browser smoke test is run, as (kind, value).

    The names are described in tools/site-standards/NAMES.md. kind is one of:
      strict  value is the `JS strict tests:` command from JS_LIBRARIES.md
      sh      value is the dated testing-js-since-<date>.sh, to run with --strict
      js      value is the dated .js, which the checks can't run yet
      plain   only a `JS tests:` command, which can't show warnings are gone
      none    no smoke test
      bad     value says what is wrong with the names found
    Each line counts by its last appearance.
    """
    dated, misnamed = [], []
    for name in sorted(os.listdir(repo)):
        if DATED_TEST.match(name):
            dated.append(name)
        elif name.startswith("testing-js-since-"):
            misnamed.append(name)
    if misnamed and not dated:
        return "bad", (f"{misnamed[0]} is not named testing-js-since-<yyyy-mmm-dd>.sh or .js "
                       "(a month like nov)")
    if len(dated) > 1:
        return "bad", f"more than one dated smoke test: {', '.join(dated)}"
    doc = contents(repo, "JS_LIBRARIES.md") or ""
    strict = STRICT_LINE.findall(doc)
    if strict:
        return "strict", strict[-1]
    if dated and dated[0].endswith(".sh"):
        if not os.access(os.path.join(repo, dated[0]), os.X_OK):
            return "bad", f"{dated[0]} is not executable"
        return "sh", dated[0]
    if dated:
        return "js", dated[0]
    if TESTS_LINE.findall(doc):
        return "plain", TESTS_LINE.findall(doc)[-1]
    return "none", ""


def verdict(code, line):
    """Print the check's one line (at most 255 characters) and exit with code."""
    line = " ".join(str(line).split())
    print(line[:255], flush=True)
    raise SystemExit(code)


def run(check):
    """Run check(repo) for the repo named on the command line, as a check.

    check() answers by calling verdict(). Anything it raises instead is
    "could not tell", never "not yet": a crash says nothing about the site.
    """
    import sys
    try:
        repo = sys.argv[2] if len(sys.argv) > 2 else ""
        if not os.path.isdir(repo):
            verdict(2, "no such checkout")
        check(repo)
        verdict(2, "the check gave no answer")
    except SystemExit:
        raise
    except Exception as e:  # noqa: BLE001 - every failure is "could not tell"
        verdict(2, f"the check failed: {type(e).__name__}: {e}")


if __name__ == "__main__":
    # `js_scripts.py smoke REPO`, for the shell checks: one line, "<kind>\t<value>".
    import sys
    try:
        if sys.argv[1:2] == ["smoke"] and len(sys.argv) == 3:
            kind, value = smoke(sys.argv[2])
            print(f"{kind}\t{' '.join(value.split())}")
        else:
            print("x\tusage: js_scripts.py smoke REPO")
    except Exception as e:  # noqa: BLE001
        print(f"x\t{type(e).__name__}: {e}")
