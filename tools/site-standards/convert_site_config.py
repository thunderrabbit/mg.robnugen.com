"""Move a site's server from its old config class file to site_config.php,
or leave it exactly as it was.

Run through convert-site-config.sh, which starts this with `python3 -I -B`,
from inside the site's checkout. Standard library only. It prints one JSON
object: `outcome` is `would_convert`, `converted`, `not_ready`, `put_back`,
`needs_a_person` or `deleted_old`, and `say` is the sentence to show. What it
does, and what it deliberately does not:

- It is for the step `server_on_site_config`. The code step before it has
  given the site Config\\Loader, which reads site_config.php and falls back to
  the old file, so the server can change files with the same code running.
- mg says which server (`site.sh next-step`, as next-step.sh asks). The old
  file and class are the ones LEGACY_FILE and LEGACY_CLASS name in this
  checkout's classes/Config/Loader.php. DreamHost servers only.
- Nothing is changed unless every line of READY holds: the checkout is clean
  and the server runs its commit, the files are where a site before the
  conversion has them, the old config passes the site's own loader, and its
  pages answer.
- Every change is followed by a comparison. The new file is written beside
  the checkout first and must load to the same settings before it goes in;
  the same again once it is in, and again once the old file has moved out,
  with the pages answering as before and `git status` unchanged. A
  comparison that fails puts the site back on its old file and says which.
- No config value is ever printed, here or on the server: only file names,
  key names, hashes and status codes. Whatever else the server prints is
  dropped, never shown.
- The old file is kept in the server user's home, mode 600, named for the
  checkout. `--delete-old` removes it; a session runs that only on Rob's word.
- `--test` converts the site's test domain's checkout instead of production's.

The server's side is convert_site_config_remote.sh beside this file. The
sentences below are pinned word for word in tests/test_convert_site_config.py.
"""

import collections
import json
import os
import re
import shlex
import subprocess
import sys
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)  # python3 -I leaves this directory off sys.path
import next_step  # noqa: E402
import scan  # noqa: E402
import server_facts  # noqa: E402

REMOTE = os.path.join(HERE, "convert_site_config_remote.sh")
LOADER = "classes/Config/Loader.php"
USAGE = "Usage: convert-site-config.sh [--dry-run | --delete-old] [--test] [--shortcode=<code>]"
TIMEOUT = 120
PAGES = ("/", "/login/")
NEEDS_PHP = (8, 4)   # the Config classes use 8.4 syntax
CLASS_NAME = re.compile(r"\A[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*\Z")
DOMAIN = re.compile(r"\A[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?:/[A-Za-z0-9._-]+)*\Z")
VERSION = re.compile(r"\A(\d{1,2})\.(\d{1,2})\Z")
HASH = re.compile(r"\A[0-9a-f]{64}\Z")
MODE = re.compile(r"\A[0-7]{3,4}\Z")
KEY = re.compile(r"\A[A-Za-z_][A-Za-z0-9_]{0,63}\Z")
ROOT_NAME = re.compile(r"\A[A-Za-z0-9_][A-Za-z0-9._-]{0,99}\Z")

NOTHING_CHANGED = "Nothing was changed. "
WHICH_SHORTCODE = "More than one site uses this checkout: {codes}. Add `--shortcode=<code>`."
NO_SERVER = "mg knows no server for `{shortcode}` (`{why}`)."
NO_TEST = "`{shortcode}` has no test domain on mg, so `--test` has nothing to convert."
NOT_DREAMHOST = "This script converts a DreamHost server only, and `{shortcode}`'s is not one."
NO_DOMAIN = "mg did not say which domain `{shortcode}`'s server answers at, so its pages cannot be loaded."
NO_LOADER_HERE = ("This checkout's `classes/Config/Loader.php` does not name a LEGACY_FILE and a LEGACY_CLASS; "
                  "the code step comes first.")
TREE_CHANGED = "This checkout has uncommitted changes."
SERVER_SILENT = "Its server did not say what it runs (`{why}`)."
OTHER_COMMIT = ("`{domain}` runs `{server}` and this checkout is at `{local}`; the server must run the code "
                "this checkout holds.")
PHP_UNKNOWN = "mg has no sweep saying which PHP serves `{domain}`."
PHP_TOO_OLD = "`{domain}` is served on PHP `{php}`; the Config classes need 8.4 or newer."
PHP_NOT_THERE = "The server has no `{php_bin}`, the PHP mg says serves `{domain}`."
NO_ANSWER = "The server did not answer `{op}` the way this script reads it."
NO_CLASSES_THERE = "The server's checkout has no `classes/Config/` Loader, Settings and EConfigInvalid."
ALREADY = "The server already has `site_config.php`."
NO_LEGACY = "The server has no `{legacy}` to convert."
KEPT_EXISTS = "`~/{kept}` is already on the server, and a conversion would need that name for the old file."
SERVER_TREE_CHANGED = "The server's checkout has changes to tracked files."
UNKNOWN_KEYS = ("The old config has keys that `Config\\Settings` does not know: {keys}. The new file would be "
                "refused for them, so add them to Settings in the code step first.")
NO_OLD_CLASS = "On the server, `{legacy}` does not declare the class `{old_class}` that the loader names."
REFUSED = "The site's own loader refuses the old config as it stands: `{why}`."
NOT_THE_OLD_FILE = "The site's loader did not read the old file, though no `site_config.php` is there."
PAGE_FAILED = "`{url}` answered {code}."
PAGE_SILENT = "`{url}` did not answer."

WOULD_CONVERT = ("Ready. It would write `site_config.php` in `{domain}`'s checkout from the values of `{legacy}`, "
                 "compare the settings the site loads before and after, and move `{legacy}` to `~/{kept}`. "
                 "Nothing was changed.")
CONVERTED = ("`{domain}` now runs on `site_config.php`. The settings it loads are the same as before "
             "(fingerprint `{fingerprint}`), {pages}, and its checkout's `git status` is unchanged. "
             "`{legacy}` is kept at `~/{kept}`, mode 600. It holds the database password in plain text; "
             "deleting it is Rob's call.")
PAGE_AS_BEFORE = "`{url}` answers {code} as before"
PUT_BACK = ("{why} So the site was put back: `{domain}` runs on `{legacy}` again, loading the same settings "
            "as before (fingerprint `{fingerprint}`), and `site_config.php` is gone again.")
NEEDS_A_PERSON = ("{why} Putting the site back did not work either ({problem}). Someone must look at `{domain}`'s "
                  "server now: `site_config.php` and `{legacy}` in its checkout, and `~/{kept}`.")
WHY_NOT_WRITTEN = "The new file could not be written beside the checkout ({problem})."
WHY_CANDIDATE_REFUSED = "The site's loader refuses the new file: `{why}`."
WHY_CANDIDATE_DIFFERS = "The new file would load to different settings than the old one."
WHY_NOT_PLACED = "The new file could not be put in the checkout."
WHY_DIFFERS_PLACED = "With `site_config.php` in place the site did not load the same settings from it."
WHY_NOT_MOVED = "The old file could not be moved out of the checkout."
WHY_DIFFERS_MOVED = "With the old file moved out the site did not load the same settings."
WHY_PAGE_CHANGED = "`{url}` answered {before} before and {after} after."
WHY_STATUS_CHANGED = "The checkout's `git status` changed."
BACK_NOT_DONE = "the server did not undo the change"
BACK_DIFFERS = "the settings it loads are not the ones from before"

NOT_CONVERTED = "The server is not on `site_config.php` alone yet, so the kept copy stays."
DELETED_OLD = "`~/{kept}` is deleted from `{domain}`'s server."
NOTHING_KEPT = "There is no `~/{kept}` on `{domain}`'s server."
BY_HAND = " A `~/Config.php.old` made by hand is there; this script leaves it, so deleting it is yours."

Options = collections.namedtuple("Options", "dry_run delete_old test shortcode")
Where = collections.namedtuple("Where", "shortcode alias directory domain php legacy old_class top")


class Done(Exception):
    """The script has decided; `.answer` is the object to print."""

    def __init__(self, outcome, say):
        super().__init__(say)
        self.answer = {"outcome": outcome, "say": say}


def not_ready(sentence):
    return Done("not_ready", NOTHING_CHANGED + sentence)


def parse_arguments(argv):
    dry_run, delete_old, test, shortcode = False, False, False, None
    for arg in argv:
        if arg == "--dry-run":
            dry_run = True
        elif arg == "--delete-old":
            delete_old = True
        elif arg == "--test":
            test = True
        elif arg.startswith("--shortcode="):
            shortcode = arg[len("--shortcode="):]
            if not next_step.SHORTCODE.match(shortcode) or len(shortcode) > next_step.SHORTCODE_MAX:
                raise next_step.Usage(USAGE)
        else:
            raise next_step.Usage(USAGE)
    if dry_run and delete_old:
        raise next_step.Usage(USAGE)
    return Options(dry_run, delete_old, test, shortcode)


def loader_names(top):
    """(LEGACY_FILE, LEGACY_CLASS) as this checkout's Config\\Loader names them, or (None, None)."""
    try:
        with open(os.path.join(top, LOADER), encoding="utf-8") as handle:
            text = handle.read()
    except (OSError, UnicodeDecodeError):
        return None, None
    found = {}
    for name in ("LEGACY_FILE", "LEGACY_CLASS"):
        match = re.search(r"const(?: string)? " + name + r" = '([^']+)';", text)
        found[name] = match.group(1).replace("\\\\", "\\") if match else ""
    legacy, old_class = found["LEGACY_FILE"], found["LEGACY_CLASS"]
    if not server_facts.LEGACY_FILE.match(legacy) or ".." in legacy.split("/") or not CLASS_NAME.match(old_class):
        return None, None
    return legacy, old_class


def where(options, cwd):
    """Which server, and what this checkout says about the old config. Stops when it cannot say."""
    try:
        top, relative = next_step.checkout(cwd)
        reply = next_step.ask_mg(next_step.Options(options.shortcode, False, False, None, None), relative)
    except next_step.Stop as stop:
        raise not_ready(stop.answer["say"]) from None
    if len(reply["shortcodes"]) > 1:
        raise not_ready(WHICH_SHORTCODE.format(codes=", ".join(f"`{code}`" for code in reply["shortcodes"])))
    if reply["site"] is None:
        raise not_ready(next_step.NOT_ON_PAGE)
    facts = reply["facts"]
    shortcode = facts["shortcode"]

    if options.test:
        target = facts.get("test")
        if not isinstance(target, dict) or not target.get("ssh_alias") or not target.get("directory"):
            raise not_ready(NO_TEST.format(shortcode=shortcode))
        php = target.get("php_version")
    else:
        target = facts.get("server")
        if not isinstance(target, dict):
            why = next_step.printable(facts.get("server_problem") or next_step.NO_SERVER)
            raise not_ready(NO_SERVER.format(shortcode=shortcode, why=why))
        php = facts.get("php_current")
    alias, directory, domain = (str(target.get(name) or "") for name in ("ssh_alias", "directory", "domain"))
    if directory.startswith("/"):
        raise not_ready(NOT_DREAMHOST.format(shortcode=shortcode))
    if not DOMAIN.match(domain):
        raise not_ready(NO_DOMAIN.format(shortcode=shortcode))

    legacy, old_class = loader_names(top)
    if legacy is None:
        raise not_ready(NO_LOADER_HERE)
    return Where(shortcode, alias, directory, domain, php, legacy, old_class, top)


def php_binary(site):
    """The served PHP's command-line binary on a DreamHost server. Stops below 8.4."""
    found = VERSION.match(str(site.php or ""))
    if not found:
        raise not_ready(PHP_UNKNOWN.format(domain=site.domain))
    version = (int(found.group(1)), int(found.group(2)))
    if version < NEEDS_PHP:
        raise not_ready(PHP_TOO_OLD.format(domain=site.domain, php=site.php))
    return os.environ.get("CONVERT_PHP_BIN") or f"/usr/local/php{version[0]}{version[1]}/bin/php"


class Server:
    """One site's server, asked through the remote script. Reads `name=value` lines and nothing else."""

    def __init__(self, site, php_bin):
        self.site, self.php_bin = site, php_bin
        with open(REMOTE, encoding="utf-8") as handle:
            self.script = handle.read()

    def ask(self, op, *more):
        """(exit code, {name: value}) of one operation. Whatever else it printed is dropped."""
        site = self.site
        words = ["bash", "-c", self.script, "convert", op, site.directory, self.php_bin, site.legacy,
                 site.old_class, *more]
        ssh = shlex.split(os.environ.get("SCAN_SSH", "ssh -o BatchMode=yes -o ConnectTimeout=15"))
        try:
            done = subprocess.run([*ssh, site.alias, " ".join(shlex.quote(word) for word in words)],
                                  capture_output=True, text=True, errors="replace",
                                  stdin=subprocess.DEVNULL, timeout=TIMEOUT)
        except subprocess.TimeoutExpired:
            return 124, {}
        said = {}
        for line in done.stdout.splitlines():
            name, mark, value = line.partition("=")
            if mark and re.fullmatch(r"[a-z_]{1,20}", name) and name not in said:
                said[name] = next_step.printable(value)[:200]
        return done.returncode, said

    def fingerprint(self):
        """(source, hash) the site's own loader gives now, or (None, why not)."""
        code, said = self.ask("fingerprint")
        if code == 0 and said.get("source") in ("site_config.php", "legacy") and HASH.match(said.get("fingerprint", "")):
            return said["source"], said["fingerprint"]
        return None, said.get("refused") or NO_ANSWER.format(op="fingerprint")


def page_status(url):
    """The status code a page answers with, redirects not followed; None when nothing answers."""
    command = os.environ.get("CONVERT_HTTP")   # the tests' stand-in for the network
    if command:
        done = subprocess.run([*shlex.split(command), url], capture_output=True, text=True, stdin=subprocess.DEVNULL)
        return int(done.stdout.strip()) if done.stdout.strip().isdigit() else None

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None

    request = urllib.request.Request(url, headers={"User-Agent": "convert-site-config"})
    try:
        with urllib.request.build_opener(NoRedirect).open(request, timeout=30) as answered:
            return answered.status
    except urllib.error.HTTPError as answered:
        return answered.code
    except (urllib.error.URLError, OSError, ValueError):
        return None


def pages(site):
    """[(url, status code)] of the two pages. A page that fails or is silent is said, not raised."""
    return [(url, page_status(url)) for url in (f"https://{site.domain}{page}" for page in PAGES)]


def pages_or_stop(site):
    found = pages(site)
    for url, code in found:
        if code is None:
            raise not_ready(PAGE_SILENT.format(url=url))
        if code >= 500:
            raise not_ready(PAGE_FAILED.format(url=url, code=code))
    return found


def ready(site, server, options):
    """Everything that must hold before a change: (what `look` said, fingerprint, the pages)."""
    code, changes = scan.git(site.top, "status", "--porcelain")
    if code != 0 or changes.strip() != "":
        raise not_ready(TREE_CHANGED)
    local = next_step.local_head(site.top)[0]
    try:
        there, _ = server_facts.head_and_branch(site.alias, site.directory)
    except server_facts.NoAnswer as why:
        raise not_ready(SERVER_SILENT.format(why=next_step.printable(str(why)))) from None
    if there != local:
        raise not_ready(OTHER_COMMIT.format(domain=site.domain, server=there[:7], local=local[:7]))

    code, look = server.ask("look")
    wanted = ("root_name", "loader", "legacy", "site_config", "kept", "kept_by_hand", "tracked_changes", "status")
    if code != 0 or not all(name in look for name in wanted) or not ROOT_NAME.match(look["root_name"]) \
            or not HASH.match(look["status"]):
        raise not_ready(NO_ANSWER.format(op="look"))
    kept = look["root_name"] + server_facts.KEPT_SUFFIX
    if options.delete_old:
        return look, None, pages_or_stop(site)

    if look.get("php", "") != site.php:
        raise not_ready(PHP_NOT_THERE.format(php_bin=server.php_bin, domain=site.domain))
    if look["loader"] != "yes":
        raise not_ready(NO_CLASSES_THERE)
    if look["site_config"] != "no":
        raise not_ready(ALREADY)
    if look["legacy"] != "yes" or not MODE.match(look.get("legacy_mode", "")):
        raise not_ready(NO_LEGACY.format(legacy=site.legacy))
    if look["kept"] != "no":
        raise not_ready(KEPT_EXISTS.format(kept=kept))
    if look["tracked_changes"] != "no":
        raise not_ready(SERVER_TREE_CHANGED)

    code, said = server.ask("keys")
    if said.get("problem") == "no-class":
        raise not_ready(NO_OLD_CLASS.format(legacy=site.legacy, old_class=site.old_class))
    if code != 0 or "unknown" not in said:
        raise not_ready(NO_ANSWER.format(op="keys"))
    unknown = [key for key in said["unknown"].split(",") if key != ""]
    if unknown:
        named = ", ".join(f"`{key}`" if KEY.match(key) else "(a key with an odd name)" for key in unknown)
        raise not_ready(UNKNOWN_KEYS.format(keys=named))

    source, fingerprint = server.fingerprint()
    if source is None:
        raise not_ready(REFUSED.format(why=fingerprint))
    if source != "legacy":
        raise not_ready(NOT_THE_OLD_FILE)
    return look, fingerprint, pages_or_stop(site)


def put_back(site, server, look, before, why, placed):
    """Undo the change, check the site loads what it loaded before, and say so."""
    kept = look["root_name"] + server_facts.KEPT_SUFFIX
    code, said = server.ask("put-back", "yes" if placed else "no", look["legacy_mode"])
    problem = None
    if code != 0 or said.get("back") != "yes":
        problem = BACK_NOT_DONE
    else:
        source, fingerprint = server.fingerprint()
        if source != "legacy" or fingerprint != before:
            problem = BACK_DIFFERS
    if problem is not None:
        return Done("needs_a_person", NEEDS_A_PERSON.format(why=why, problem=problem, domain=site.domain,
                                                            legacy=site.legacy, kept=kept))
    return Done("put_back", PUT_BACK.format(why=why, domain=site.domain, legacy=site.legacy, fingerprint=before[:8]))


def convert(site, server, look, before, pages_before):
    """Each change, then its comparison. Raises Done with how it ended."""
    kept = look["root_name"] + server_facts.KEPT_SUFFIX

    code, said = server.ask("generate")
    if code != 0 or not HASH.match(said.get("candidate", "")):
        if "refused" in said:
            why = WHY_CANDIDATE_REFUSED.format(why=said["refused"])
        else:
            why = WHY_NOT_WRITTEN.format(problem=said.get("problem") or "no answer")
        raise put_back(site, server, look, before, why, placed=False)
    if said["candidate"] != before:
        raise put_back(site, server, look, before, WHY_CANDIDATE_DIFFERS, placed=False)

    code, said = server.ask("place")
    if code != 0 or said.get("placed") != "yes":
        # The file may be half there: it was not before, so it is this run's to remove.
        raise put_back(site, server, look, before, WHY_NOT_PLACED, placed=True)
    if server.fingerprint() != ("site_config.php", before):
        raise put_back(site, server, look, before, WHY_DIFFERS_PLACED, placed=True)

    code, said = server.ask("move")
    if code != 0 or said.get("moved") != "yes":
        raise put_back(site, server, look, before, WHY_NOT_MOVED, placed=True)
    if server.fingerprint() != ("site_config.php", before):
        raise put_back(site, server, look, before, WHY_DIFFERS_MOVED, placed=True)
    for (url, was), (_, now) in zip(pages_before, pages(site)):
        if now != was:
            why = WHY_PAGE_CHANGED.format(url=url, before=was, after="no answer" if now is None else now)
            raise put_back(site, server, look, before, why, placed=True)
    code, after = server.ask("look")
    if code != 0 or after.get("status") != look["status"]:
        raise put_back(site, server, look, before, WHY_STATUS_CHANGED, placed=True)

    answered = " and ".join(PAGE_AS_BEFORE.format(url=url, code=code) for url, code in pages_before)
    raise Done("converted", CONVERTED.format(domain=site.domain, fingerprint=before[:8], pages=answered,
                                             legacy=site.legacy, kept=kept))


def delete_old(site, server, look):
    kept = look["root_name"] + server_facts.KEPT_SUFFIX
    if look["site_config"] != "yes" or look["legacy"] != "no":
        raise not_ready(NOT_CONVERTED)
    code, said = server.ask("delete-old")
    if code != 0 or said.get("deleted") not in ("yes", "none"):
        raise not_ready(NOT_CONVERTED if said.get("problem") == "not-converted" else NO_ANSWER.format(op="delete-old"))
    by_hand = BY_HAND if said.get("kept_by_hand") == "yes" else ""
    if said["deleted"] == "yes":
        raise Done("deleted_old", DELETED_OLD.format(kept=kept, domain=site.domain) + by_hand)
    raise not_ready(NOTHING_KEPT.format(kept=kept, domain=site.domain) + by_hand)


def answer(argv, cwd=None):
    """The object to print for a command line. A Usage is the caller's to report."""
    options = parse_arguments(argv)
    try:
        site = where(options, cwd or os.getcwd())
        server = Server(site, php_binary(site))
        look, before, pages_before = ready(site, server, options)
        if options.delete_old:
            delete_old(site, server, look)
        if options.dry_run:
            raise Done("would_convert", WOULD_CONVERT.format(domain=site.domain, legacy=site.legacy,
                                                             kept=look["root_name"] + server_facts.KEPT_SUFFIX))
        convert(site, server, look, before, pages_before)
    except Done as done:
        return done.answer
    raise AssertionError("every path ends in Done")


def main(argv):
    try:
        print(json.dumps(answer(argv, os.getcwd())))
    except next_step.Usage as usage:
        print(usage, file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
