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
- how many inline <script> blocks there are.

PHP blocks are blanked before reading, so `src="<?= $x ?>"` shows as dynamic
rather than being misread. A script written out from inside a PHP string is
not seen.
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


def blank_php(text):
    """The text with each PHP block replaced by DYNAMIC marks, newlines kept."""
    return PHP_BLOCK.sub(lambda m: re.sub(r"[^\n]", DYNAMIC, m.group(0)), text)


def tracked_files(repo):
    done = subprocess.run(["git", "--no-optional-locks", "-C", repo, "ls-files", "-z"],
                          capture_output=True, text=True)
    if done.returncode != 0:
        raise RuntimeError("could not list the checkout's files with git")
    for path in done.stdout.split("\0"):
        if not path.endswith(EXTENSIONS) or path.endswith((".min.js", ".min.mjs")):
            continue
        if SKIP_DIRS.intersection(path.split("/")[:-1]):
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


def read(repo):
    """Everything the checks need to know about the repo's scripts, as a dict."""
    found = {"scripts": [], "importmap": [], "imports": [], "inline": [], "problems": []}
    for path in tracked_files(repo):
        try:
            with open(os.path.join(repo, path), encoding="utf-8", errors="replace") as f:
                raw = f.read()
        except OSError:
            continue
        if path.endswith((".js", ".mjs")):
            imports_in(raw, path, 1, found["imports"])
            continue
        text = blank_php(raw) if path.endswith((".php", ".phtml")) else raw
        for m in SCRIPT.finditer(text):
            attrs = attributes(m.group(1))
            line = line_of(text, m.start())
            body = m.group(2)
            kind = attrs.get("type", "").strip().lower()
            if "src" in attrs:
                src = attrs["src"].strip()
                found["scripts"].append({
                    "src": src, "file": path, "line": line, "dynamic": DYNAMIC in src or src == "",
                    "integrity": attrs.get("integrity", "").strip().startswith(("sha256-", "sha384-", "sha512-")),
                    "crossorigin": "crossorigin" in attrs,
                })
            elif kind == "importmap":
                read_importmap(body, path, line, found)
            elif body.strip():
                found["inline"].append({"file": path, "line": line})
                if kind == "module":
                    imports_in(body, path, line, found["imports"])
    return found


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
    """Every script URL the site loads: <script src>s and import-map targets."""
    urls = [s["src"] for s in found["scripts"] if not s["dynamic"]]
    urls += [e["url"] for e in found["importmap"]]
    urls += [i["spec"] for i in found["imports"] if is_external(i["spec"]) and not i["dynamic"]]
    return urls


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
