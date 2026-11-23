"""Look up a checkout's first open step on mg's standards page.

Run through next-step.sh, which starts this with `python3 -I -B`, from inside
a site's checkout. Standard library only. It prints one JSON object whose
`outcome` says what the /next-mg-step skill does next: `say` (show the words
and stop) or `ask` (a question only Rob can answer). What it does, and what
it deliberately does not:

- mg chooses the step. The script asks mg over ssh (`site.sh next-step`) and
  takes the first open step, in the order mg gives, and never any other.
- Given the same answer from mg, the same server commit and the same
  checkout, it prints the same words. Every sentence is a constant below,
  and the tests pin each one.
- It never writes to any repo and never changes anything on mg. Its git
  calls are scan.py's read-only ones, and it never runs `site-set`: it asks.
- It never runs a site's safe suite.
- It never guesses a model.
"""

import collections
import json
import os
import re
import shlex
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)  # python3 -I leaves this directory off sys.path
import scan  # noqa: E402

BRIEF = os.path.join(HERE, "next_step_brief.md")

NOT_UNDER_WORK = "This repo isn't under ~/work, so it can't be on mg's standards page."
UNREACHABLE = "I can't reach mg from here (`{error}`), so /next-mg-step can't look up the step."
REFUSED = "mg refused the lookup: `{sentence}`"
NOT_THE_ANSWER = "its answer was not the one this script reads"
WHICH_SHORTCODE = "More than one site uses this checkout: {codes}. Which shortcode is meant?"
NOT_ON_PAGE = "This repo isn't on mg's standards page, so /next-mg-step has nothing for it."
UP_TO_DATE = "`{shortcode}` is up to date on mg's standards page."
NOBODY = "Nobody can reach `{shortcode}` yet, so these are yours first:"
USAGE = ("Usage: next-step.sh [--shortcode=<code>] [--leave-upgrade] [--session-model=<model>] "
         "[--confirm --base=<commit>]")
MODELS = ("haiku", "sonnet", "opus", "fable")   # cheapest first; mg's StepModel is the judge of the list
SHORTCODE = re.compile(r"\A[a-z0-9]+(?:-[a-z0-9]+)*\Z")   # Names::SHORTCODE, at most 24 characters
SHORTCODE_MAX = 24
BASE = re.compile(r"\A[0-9a-f]{7,40}\Z")
MG_TIMEOUT = 60

Options = collections.namedtuple("Options", "shortcode leave_upgrade confirm base session_model")


class Stop(Exception):
    """The script has decided; `.answer` is the object to print."""

    def __init__(self, answer):
        super().__init__(answer.get("say", ""))
        self.answer = answer


class Usage(Exception):
    """The command line was not one this script takes."""


def say_stop(text, **more):
    return Stop(dict({"outcome": "say", "say": text}, **more))


def printable(text):
    return "".join(ch for ch in text if ch.isprintable())


def last_line(text):
    """The last line of a command's stderr that says anything, or `no answer`."""
    lines = [printable(line).strip() for line in text.splitlines()]
    lines = [line for line in lines if line != ""]
    return lines[-1][:255] if lines else "no answer"


def parse_arguments(argv):
    shortcode, leave_upgrade, confirm, base, session_model = None, False, False, None, None
    for arg in argv:
        if arg == "--leave-upgrade":
            leave_upgrade = True
        elif arg == "--confirm":
            confirm = True
        elif arg.startswith("--shortcode="):
            shortcode = arg[len("--shortcode="):]
            if not SHORTCODE.match(shortcode) or len(shortcode) > SHORTCODE_MAX:
                raise Usage(USAGE)
        elif arg.startswith("--base="):
            base = arg[len("--base="):]
            if not BASE.match(base):
                raise Usage(USAGE)
        elif arg.startswith("--session-model="):
            session_model = arg[len("--session-model="):]
            if session_model not in MODELS:
                raise Usage(USAGE)
        else:
            raise Usage(USAGE)
    if confirm != (base is not None):
        raise Usage(USAGE)
    return Options(shortcode, leave_upgrade, confirm, base, session_model)


def checkout(cwd):
    """(the checkout's top directory, its path under ~/work). Stops when there is none."""
    code, top = scan.git(cwd, "rev-parse", "--show-toplevel")
    top = top.strip()
    if code != 0 or top == "":
        raise say_stop(NOT_UNDER_WORK)
    top = os.path.realpath(top)
    root = os.path.realpath(os.environ.get("WORK_ROOT", os.path.expanduser("~/work")))
    if not top.startswith(root.rstrip(os.sep) + os.sep):
        raise say_stop(NOT_UNDER_WORK)
    return top, os.path.relpath(top, root)


def well_formed(reply):
    """Whether mg's answer is the object `site.sh next-step` prints, as far as this script reads it."""
    if not isinstance(reply, dict) or not all(key in reply for key in ("site", "shortcodes", "facts", "steps_commit")):
        return False
    if not isinstance(reply["shortcodes"], list) or not all(isinstance(code, str) for code in reply["shortcodes"]):
        return False
    site = reply["site"]
    if site is None:
        return True
    if not isinstance(site, dict) or not isinstance(reply["facts"], dict):
        return False
    if not isinstance(site.get("shortcode"), str) or not isinstance(site.get("standing"), dict):
        return False
    steps = site.get("open_steps")
    return isinstance(steps, list) and all(isinstance(step, dict) for step in steps)


def ask_mg(options, relative):
    """mg's answer to `site.sh next-step`, over ssh. Stops when mg cannot be reached or refuses."""
    asked = "--shortcode=" + options.shortcode if options.shortcode else "--checkout=" + relative
    command = (os.environ.get("SCAN_SITE_CMD", shlex.quote(os.path.join(HERE, "site.sh")))
               + " next-step " + shlex.quote(asked))
    try:
        done = subprocess.run(["bash", "-c", command], capture_output=True, text=True,
                              stdin=subprocess.DEVNULL, timeout=MG_TIMEOUT)
    except subprocess.TimeoutExpired:
        raise say_stop(UNREACHABLE.format(error="no answer")) from None
    # ssh keeps 255 for its own failures; anything else is what mg said.
    if done.returncode == 255:
        raise say_stop(UNREACHABLE.format(error=last_line(done.stderr)))
    if done.returncode != 0:
        raise say_stop(REFUSED.format(sentence=last_line(done.stderr)))
    try:
        reply = json.loads(done.stdout)
    except ValueError:
        reply = None
    if not well_formed(reply):
        raise say_stop(REFUSED.format(sentence=NOT_THE_ANSWER))
    return reply


def site_or_stop(reply):
    """(site, facts, steps_commit), or a stop, in the order the skill reads the answer."""
    if len(reply["shortcodes"]) > 1:
        codes = ", ".join(f"`{code}`" for code in reply["shortcodes"])
        raise Stop({"outcome": "ask", "say": "", "ask": WHICH_SHORTCODE.format(codes=codes)})
    site = reply["site"]
    if site is None:
        raise say_stop(NOT_ON_PAGE)
    if not site["open_steps"]:
        raise say_stop(UP_TO_DATE.format(shortcode=site["shortcode"]))
    if site["standing"].get("mark") == "face":
        parts = [NOBODY.format(shortcode=site["shortcode"])]
        for step in site["open_steps"]:
            if step.get("whose_move") == "rob":
                parts += [f"**`{step.get('title')}`**", step.get("how_to") or ""]
        raise say_stop("\n\n".join(parts))
    return site, reply["facts"], reply["steps_commit"]


def lookup(options, cwd):
    top, relative = checkout(cwd)
    site, facts, steps_commit = site_or_stop(ask_mg(options, relative))
    raise NotImplementedError


def answer(argv, cwd=None):
    """The object to print for a command line. A Usage is the caller's to report."""
    options = parse_arguments(argv)
    try:
        return lookup(options, cwd or os.getcwd())
    except Stop as stop:
        return stop.answer


def main(argv):
    try:
        print(json.dumps(answer(argv, os.getcwd())))
    except Usage as usage:
        print(usage, file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
