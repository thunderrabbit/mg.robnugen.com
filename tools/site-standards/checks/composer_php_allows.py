"""composer_php_allows.py CONSTRAINT VERSION: exit 0 if Composer's PHP
constraint allows some release of VERSION (major.minor), 1 if not, 3 if the
constraint is a shape this does not read.

A helper for the checks beside it, run with `python3 -I`. It reads the
shapes sites write in require.php: `>=8.1`, `^8.1`, `~8.1.2`, `8.3.*`,
`>=8.1 <8.4`, `^8.1 || ^8.3`. "Some release" means 8.3.0 or a later 8.3.x,
so `>=8.3.4` allows 8.3.
"""

import re
import sys

VERSION = re.compile(r"\A(\d+)(?:\.(\d+))?(?:\.(\d+))?\Z")


def parts(text):
    found = VERSION.match(text)
    if not found:
        raise ValueError(text)
    return tuple(int(p) if p is not None else None for p in found.groups())


def filled(version):
    return tuple(p or 0 for p in version)


def atom_allows(atom, candidate):
    """Whether one constraint such as >=8.1 or ^8.1 allows a full x.y.z."""
    if atom in ("*", ""):
        return True
    wild = re.match(r"\A(\d+)(?:\.(\d+))?\.\*\Z", atom)
    if wild:
        major, minor = int(wild.group(1)), wild.group(2)
        return candidate[0] == major and (minor is None or candidate[1] == int(minor))
    found = re.match(r"\A(>=|<=|>|<|!=|==|=|\^|~)?v?(.+)\Z", atom)
    if not found:
        raise ValueError(atom)
    op, given = found.group(1) or "=", parts(found.group(2))
    low = filled(given)
    if op == "^":
        high = (low[0] + 1, 0, 0) if low[0] > 0 else (0, low[1] + 1, 0)
        return low <= candidate < high
    if op == "~":
        if given[2] is None:
            high = (low[0] + 1, 0, 0)
        else:
            high = (low[0], low[1] + 1, 0)
        return low <= candidate < high
    if op in ("=", "=="):
        if given[1] is None:
            return candidate[0] == low[0]
        if given[2] is None:
            return candidate[:2] == low[:2]
        return candidate == low
    return {">=": candidate >= low, "<=": candidate <= low, ">": candidate > low,
            "<": candidate < low, "!=": candidate != low}[op]


def allows(constraint, major_minor):
    major, minor = (int(p) for p in major_minor.split("."))
    candidates = [(major, minor, patch) for patch in (0, 99)]
    for alternative in re.split(r"\s*\|\|?\s*", constraint.strip()):
        atoms = [a for a in re.split(r"[\s,]+", alternative.strip()) if a]
        # "8.1 - 8.4" is a hyphen range.
        if len(atoms) == 3 and atoms[1] == "-":
            atoms = [">=" + atoms[0], "<" + ".".join(str(p) for p in _after(parts(atoms[2])))]
        for candidate in candidates:
            if all(atom_allows(atom, candidate) for atom in atoms):
                return True
    return False


def _after(version):
    """The first version past a hyphen range's upper end: 8.4 -> 8.5.0, 8.4.1 -> 8.4.2."""
    if version[1] is None:
        return (version[0] + 1, 0, 0)
    if version[2] is None:
        return (version[0], version[1] + 1, 0)
    return (version[0], version[1], version[2] + 1)


def main(argv):
    if len(argv) != 3 or not re.fullmatch(r"\d+\.\d+", argv[2]):
        print("usage: composer_php_allows.py CONSTRAINT MAJOR.MINOR", file=sys.stderr)
        return 3
    try:
        return 0 if allows(argv[1], argv[2]) else 1
    except ValueError:
        return 3


if __name__ == "__main__":
    sys.exit(main(sys.argv))
