#!/usr/bin/env bash
# js_libraries_current REPO: every library the site loads is on its newest
# major release (for a 0.x library, within ten minors of the newest), and
# its release is not marked deprecated. A library the site has decided to hold
# on an older major says so in JS_LIBRARIES.md, as "Stays on <package>
# <major>: <why>" (../NAMES.md), and is then counted as held, not behind.
#
# The one check that reads the network: it asks the npm registry (and cdnjs's
# API, for cdnjs URLs) which release is newest. Read-only, nothing is sent
# about the site beyond the package names. Libraries come from CDN URLs whose
# path names the package, copies in the repo named <package>-<x.y.z>.js
# (whether a <script src> or PHP names them), and package.json's dependencies.
# Everything found wanting is listed in the one line, not just the first.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
out="$(python3 -I -B - "$HERE" "$REPO" <<'PY'
import json, re, sys, urllib.parse, urllib.request
sys.path.insert(0, sys.argv[1])
import js_scripts as j

ZERO_MINORS = 10
V = r"v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)"
PKG = r"((?:@[a-z0-9][\w.-]*/)?[a-z0-9][\w.-]*)"
NPM_URLS = [
    re.compile(rf"\A(?:https?:)?//cdn\.jsdelivr\.net/npm/{PKG}@{V}(?:/|\Z)"),
    re.compile(rf"\A(?:https?:)?//unpkg\.com/{PKG}@{V}(?:/|\Z)"),
    re.compile(rf"\A(?:https?:)?//esm\.sh/(?:v\d+/)?{PKG}@{V}(?:/|\?|\Z)"),
    re.compile(rf"\A(?:https?:)?//ga\.jspm\.io/npm:{PKG}@{V}(?:/|\Z)"),
]
JQUERY = [
    (re.compile(rf"\A(?:https?:)?//code\.jquery\.com/ui/{V}/"), "jquery-ui"),
    (re.compile(rf"\A(?:https?:)?//code\.jquery\.com/mobile/{V}/"), "jquery-mobile"),
    (re.compile(rf"\A(?:https?:)?//code\.jquery\.com/jquery-migrate-{V}(?:\.min)?\.js"), "jquery-migrate"),
    (re.compile(rf"\A(?:https?:)?//code\.jquery\.com/jquery-{V}(?:\.slim)?(?:\.min)?\.js"), "jquery"),
]
CDNJS = re.compile(rf"\A(?:https?:)?//cdnjs\.cloudflare\.com/ajax/libs/([\w.-]+)/{V}/")
COPY = re.compile(rf"(?:\A|/)([a-z0-9][\w.-]*?)[-@.]{V}(?:\.min)?\.m?js(?:\?|\Z)")
STAYS = re.compile(r"^Stays on ((?:@[a-z0-9][\w.-]*/)?[a-z0-9][\w.-]*) (\d+):", re.M | re.I)


def fetch(url):
    request = urllib.request.Request(url, headers={
        "Accept": "application/vnd.npm.install-v1+json, application/json",
        "User-Agent": "site-standards-check"})
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return json.load(response)
    except urllib.error.HTTPError as e:
        if e.code == 404:
            return None
        j.verdict(2, f"{url} answered {e.code}")
    except (OSError, ValueError) as e:
        j.verdict(2, f"could not ask {urllib.parse.urlsplit(url).netloc}: {e}")


def parts(version):
    return [int(n) for n in version.split("-")[0].split(".")[:3]]


def held(repo):
    """{package: major} the site says it stays on, from JS_LIBRARIES.md."""
    doc = j.contents(repo, "JS_LIBRARIES.md") or ""
    return {name.lower(): int(major) for name, major in STAYS.findall(doc)}


def behind(version, latest, stays=None):
    """Why version is too far behind latest, or None; "held" when the site holds it there."""
    have, newest = parts(version), parts(latest)
    if have[0] < newest[0]:
        if stays is not None and have[0] == stays:
            return "held"
        return f"{version} is behind the newest major ({latest})"
    if have[0] == 0 and newest[0] == 0 and newest[1] - have[1] > ZERO_MINORS:
        return f"{version} is more than {ZERO_MINORS} releases behind {latest}"
    return None


def identify(url, local):
    """(registry, package, version) for a URL, or None if it names no library."""
    for pattern in NPM_URLS:
        m = pattern.match(url)
        if m:
            return "npm", m.group(1), m.group(2)
    for pattern, name in JQUERY:
        m = pattern.match(url)
        if m:
            return "npm", name, m.group(1)
    m = CDNJS.match(url)
    if m:
        return "cdnjs", m.group(1), m.group(2)
    if local:
        m = COPY.search(url)
        if m:
            return "npm", m.group(1).lower(), m.group(2)
    return None


def line(before, items, after=""):
    """before + the items + after, the items cut where the 255-character line would run out."""
    room = 255 - len(before) - len(after)
    text = ", ".join(items)
    if len(text) > room:
        text = text[:max(room - 2, 0)].rsplit(", ", 1)[0] + ", …"
    return before + text + after


def check(repo):
    found = j.read(repo)
    if found["problems"]:
        j.verdict(2, found["problems"][0])
    wanted = {}
    items = [(s["src"], s) for s in found["scripts"] if not s["dynamic"]]
    items += [(e["url"], e) for e in found["importmap"]]
    items += [(i["spec"], i) for i in found["imports"] if j.is_external(i["spec"]) and not i["dynamic"]]
    items += [(n["path"], n) for n in found["named"] if n["path"] is not None]
    nameless = []
    for url, item in items:
        external = j.is_external(url)
        if not external and not j.has_version(url):
            continue  # the site's own code
        lib = identify(url, not external)
        if lib is None:
            nameless.append(f"{url} ({j.where(item)})")
            continue
        wanted.setdefault(lib[:2], set()).add((lib[2], f"{url} ({j.where(item)})"))
    if nameless:
        j.verdict(2, line(f"can't tell which package {len(nameless)} script(s) are, so name each copy "
                          "<npm package>-<x.y.z>.js or load it from a CDN path that names it: ",
                          sorted(set(nameless))))
    for name, spec in j.dependencies(repo).items():
        m = re.search(r"\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?", spec)
        if not m:
            j.verdict(2, f"package.json's {name} is \"{spec}\", which names no x.y.z version")
        wanted.setdefault(("npm", name), set()).add((m.group(0), f"package.json's {name}"))
    if not wanted:
        j.verdict(0, "the site loads no third-party libraries")
    stays = held(repo)
    wrong, kept = [], 0
    for (registry, name), uses in sorted(wanted.items()):
        if registry == "cdnjs":
            doc = fetch(f"https://api.cdnjs.com/libraries/{urllib.parse.quote(name)}?fields=version")
            if doc is None or not doc.get("version"):
                j.verdict(2, f"cdnjs has no library named {name}")
            latest, versions = doc["version"], {}
        else:
            doc = fetch(f"https://registry.npmjs.org/{urllib.parse.quote(name, safe='@')}")
            if doc is None:
                j.verdict(2, f"npm has no package named {name}; name the copy after its npm package")
            latest = (doc.get("dist-tags") or {}).get("latest")
            versions = doc.get("versions") or {}
            if not latest:
                j.verdict(2, f"npm gives no latest release for {name}")
        for version, where in sorted(uses):
            meta = versions.get(version) or {}
            if meta.get("deprecated"):
                wrong.append(f"{name} {version} is marked deprecated on npm ({where})")
                continue
            why = behind(version, latest, stays.get(name))
            if why == "held":
                kept += 1
            elif why:
                wrong.append(f"{name} {why} ({where})")
    if wrong:
        j.verdict(1, line(f"{len(wrong)} not current: ", wrong))
    note = f", {kept} held on an older major on purpose" if kept else ""
    j.verdict(0, f"all {len(wanted)} libraries are on a current release{note}")


j.run(check)
PY
)"
case $? in
  0) pass "$out" ;;
  1) not_yet "$out" ;;
  *) unknown "${out:-the check could not run}" ;;
esac
