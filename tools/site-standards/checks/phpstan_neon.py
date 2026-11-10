"""phpstan_neon.py COMMAND ...: read a repo's PHPStan configs for the checks
beside it, without running PHPStan. Run with `python3 -I`.

    configs REPO                   the configs standards-mcp's run-phpstan.sh
                                   runs, one per line: phpstan.neon, else
                                   phpstan.neon.dist, else each
                                   phpstan-level-*.neon tier (not baselines)
    php-version FILE               "MIN MAX" from parameters.phpVersion (a
                                   number or {min, max}); exit 1 if not set
    level FILE                     parameters.level; exit 1 if not set
    baselined-undefined REPO CFG   how many "undefined property" findings the
                                   baselines CFG includes are hiding

Reads the flat shapes these files are written in, not all of NEON. Exit 3
means a file could not be read.
"""

import glob
import os
import re
import sys


def configs(repo):
    for name in ("phpstan.neon", "phpstan.neon.dist"):
        if os.path.isfile(os.path.join(repo, name)):
            return [name]
    tiers = sorted(os.path.basename(p) for p in glob.glob(os.path.join(repo, "phpstan-level-*.neon")))
    return [t for t in tiers if not t.endswith("-baseline.neon")]


def read(path):
    with open(path, encoding="utf-8") as handle:
        return handle.read()


def php_version(text):
    """(min, max) as PHP_VERSION_ID-style numbers, or None."""
    found = re.search(r"^[ \t]+phpVersion:[ \t]*(\d+)[ \t]*$", text, re.M)
    if found:
        version = int(found.group(1))
        return version, version
    found = re.search(r"^[ \t]+phpVersion:[ \t]*\{([^}]*)\}", text, re.M)
    if found:
        body = found.group(1)
    else:
        found = re.search(r"^([ \t]+)phpVersion:[ \t]*\n((?:\1[ \t]+\w+:[^\n]*\n?)+)", text, re.M)
        if not found:
            return None
        body = found.group(2)
    low = re.search(r"\bmin:\s*(\d+)", body)
    high = re.search(r"\bmax:\s*(\d+)", body)
    if not low or not high:
        return None
    return int(low.group(1)), int(high.group(1))


def level(text):
    found = re.search(r"^[ \t]+level:[ \t]*([A-Za-z0-9]+)", text, re.M)
    return found.group(1) if found else None


def included_baselines(text):
    """The files under includes: whose name says baseline."""
    found = re.search(r"^includes:[ \t]*\n((?:[ \t]+-[^\n]*\n?)+)", text, re.M)
    if not found:
        return []
    names = [line.strip()[1:].strip().strip("\"'") for line in found.group(1).splitlines()]
    return [name for name in names if "baseline" in name]


def undefined_properties(text):
    """The findings a baseline hides that are undefined properties, counted."""
    total = 0
    for entry in re.split(r"^[ \t]+-[ \t]*$", text, flags=re.M)[1:]:
        if "identifier: property.notFound" in entry or "undefined property" in entry:
            count = re.search(r"^[ \t]+count:[ \t]*(\d+)", entry, re.M)
            total += int(count.group(1)) if count else 1
    return total


def main(argv):
    try:
        if len(argv) == 3 and argv[1] == "configs":
            print("\n".join(configs(argv[2])))
            return 0
        if len(argv) == 3 and argv[1] == "php-version":
            found = php_version(read(argv[2]))
            if found is None:
                return 1
            print(f"{found[0]} {found[1]}")
            return 0
        if len(argv) == 3 and argv[1] == "level":
            found = level(read(argv[2]))
            if found is None:
                return 1
            print(found)
            return 0
        if len(argv) == 4 and argv[1] == "baselined-undefined":
            config = os.path.join(argv[2], argv[3])
            here = os.path.dirname(config)
            total = 0
            for name in included_baselines(read(config)):
                path = os.path.normpath(os.path.join(here, name))
                if os.path.isfile(path):
                    total += undefined_properties(read(path))
            print(total)
            return 0
    except (OSError, UnicodeDecodeError):
        return 3
    print(__doc__.strip().splitlines()[0], file=sys.stderr)
    return 3


if __name__ == "__main__":
    sys.exit(main(sys.argv))
