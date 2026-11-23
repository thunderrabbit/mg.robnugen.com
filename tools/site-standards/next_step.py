"""Look up a checkout's first open step on mg's standards page.

Run through next-step.sh, which starts this with `python3 -I -B`, from inside
a site's checkout. Standard library only. It prints one JSON object whose
`outcome` says what the /next-mg-step skill does next: `say` (show the words
and stop), `ask` (a question only Rob can answer), `dispatch` (start a
subagent on the step's model with the `brief`) or `do` (the session does the
step itself under the `rules`). What it does, and what it deliberately does
not:

- mg chooses the step. The script asks mg over ssh (`site.sh next-step`) and
  takes the first open step, in the order mg gives, and never any other.
- Given the same answer from mg, the same server commit and the same
  checkout, it prints the same words. Every sentence is a constant below,
  and the tests pin each one.
- It never writes to any repo and never changes anything on mg. Its git
  calls are scan.py's read-only ones, and it never runs `site-set`: it asks.
- The step's check is run here in the environment a scan gives it
  (scan.py's check_environment), so it answers what it will answer in the
  scan. A site's safe suite is never run (NEVER_RUN_IN_A_LOOKUP).
- A step's model is its `model:` line, else its track's, read with git from
  the step files at the commit mg last scanned. A step with none there is
  never run on a guess.
- The brief and the rules come from next_step_brief.md beside this file,
  which names no site.
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

FROM_SCAN = "These steps come from the scan of `{last_checked_utc}` UTC: {skipped_reason}"
YOURS = "`{shortcode}`'s next step is yours: **`{title}`** (`{detail}`)."
STEP_LINE = "`{shortcode}`, track `{track}`, **`{title}`** (`{detail}`)"
SERVED = ", served on PHP `{php_current}`"
TEST_SERVED = ", test domain on PHP `{test_php}`"
NO_SWEEP = "; mg has no sweep for its PHP yet"
DEPLOY_TO_TEST = "mg will scan `{shortcode}` once this commit is deployed to `{domain}`; production can wait."
UPGRADE_LEFT = ("`{shortcode}` has `upgrade_branch` set to `{upgrade}` on mg; clearing it "
                "(`site-set --shortcode={shortcode} --upgrade=`) is your call.")
SECOND_LINE = "mg will scan `{branch}`'s tip for `{shortcode}`."
MAY_I_SET = ("The server runs commits this branch lacks, so `{branch}` is a second line being prepared "
             "beside the live one. May I run `~/work/rob/mg.robnugen.com/tools/site-standards/site.sh "
             "site-set --shortcode={shortcode} --upgrade={branch}`, so that mg scans `{branch}`'s tip "
             "for `{shortcode}`?")
SERVER_SILENT = "Its server did not say what it runs (`{why}`)."
NO_SERVER = "there is no server to ask"   # the scanner's words

ALREADY_DONE = "Already done here; it's waiting on a deploy."
COULD_NOT_TELL = "The check could not tell: `{line}`"
SCAN_REMINDER = ("Once you've approved and deployed this{where}, run on Lemur 13:\n"
                 "`~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh {shortcode}`")
# Checks this script never runs, and what it says in their place.
NEVER_RUN_IN_A_LOOKUP = {"suite_passes_on_php": "only the scan runs a site's safe suite"}

NO_MODEL = "mg's last scan has no model for **`{title}`**; a scan will bring it."
ASKS_FOR = "This step asks for `{model}`; this session runs on `{session}`."
CHECK_RUN = ("Run this exactly as written. It prints one line and exits 0 when the step is done, "
             "1 when it is not yet, 2 when it could not tell.\n\n    {command}\n\n{just_now}")
JUST_SAID = "Just now it said: `{line}`"
JUST_COULD_NOT = "Just now it could not tell: `{line}`"
CHECK_IS_MGS = "mg answers this step's check from its own tables; there is nothing to run here."
CHECK_IS_SCANS = "Only the scan runs this step's check (a site's safe suite). Do not run it."
SERVED_BRIEF = "served on PHP `{php_current}`"
NO_SWEEP_BRIEF = "mg has no sweep for its PHP yet"
CHECK_HEADING = "### The check that decides it"
STEPS_DIR = "tools/site-standards/steps/"
STEP_NAME = re.compile(r"\A[a-z][a-z0-9_]{0,63}\Z")     # Names::STEP
SUBAGENT_MODELS = ("haiku", "sonnet")   # a step on any other model is done by the session
PLACEHOLDERS = ("shortcode", "top", "track", "title", "slug", "detail", "served", "explanation", "how_to", "check")
RULES_MARK = "<!-- rules -->\n"
SUBAGENT_MARK = "<!-- subagent -->\n"

Options = collections.namedtuple("Options", "shortcode leave_upgrade confirm base session_model")
# kind: "script" (a check script here), "none" (mg's own check, or an idea with
# no check yet) or "never" (NEVER_RUN_IN_A_LOOKUP). outcome: pass, fail,
# unknown, or None when nothing was run.
Check = collections.namedtuple("Check", "kind outcome line script param site_env")


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
    if not isinstance(reply["facts"].get("shortcode"), str):   # check_environment reads it
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


def step_line(site, step):
    """Which step it is, and the PHP the site is served on today, as the sweep saw it."""
    line = STEP_LINE.format(shortcode=site["shortcode"], track=step.get("track"), title=step.get("title"),
                            detail=step.get("detail"))
    if site.get("php_current"):
        line += SERVED.format(php_current=site["php_current"])
        if site.get("test_php"):   # said only beside the served PHP, as the skill has it
            line += TEST_SERVED.format(test_php=site["test_php"])
    else:
        line += NO_SWEEP
    return line + "."


def local_head(top):
    """(commit, branch or None on a detached HEAD) of the checkout, read as scan_site reads them."""
    commit = scan.git(top, "rev-parse", "HEAD")[1].strip()
    code, branch = scan.git(top, "symbolic-ref", "--short", "-q", "HEAD")
    branch = branch.strip() if code == 0 and branch.strip() != "" else None
    return commit, branch


def second_line(site, facts, top, options):
    """(notes, question or None, server commit, server branch): whether this
    branch is a second line beside the one the server runs.

    The server is asked the way the scanner asks it. Most sites have one
    line, and for them `upgrade_branch` is left alone; nothing here sets it.
    """
    shortcode = site["shortcode"]
    server = facts.get("server")
    if not isinstance(server, dict):
        return [SERVER_SILENT.format(why=printable(facts.get("server_problem") or NO_SERVER))], None, None, None
    try:
        server_commit, server_branch = scan.server_facts.head_and_branch(
            str(server.get("ssh_alias") or ""), str(server.get("directory") or ""))
    except scan.server_facts.NoAnswer as why:
        return [SERVER_SILENT.format(why=printable(str(why)))], None, None, None

    upgrade = facts.get("upgrade_branch")
    test = facts.get("test")
    notes, ask = [], None
    if scan.git(top, "merge-base", "--is-ancestor", server_commit, "HEAD")[0] == 0:
        # One line: Rob deploys whichever branch is the tip.
        if isinstance(test, dict) and not upgrade:
            notes.append(DEPLOY_TO_TEST.format(shortcode=shortcode, domain=test.get("domain")))
        if upgrade:
            notes.append(UPGRADE_LEFT.format(shortcode=shortcode, upgrade=upgrade))
    else:
        # The server runs commits this branch lacks (or ones this checkout does not have).
        branch = local_head(top)[1]
        if branch is None:
            pass
        elif upgrade == branch:
            notes.append(SECOND_LINE.format(branch=branch, shortcode=shortcode))
        elif not options.leave_upgrade:
            ask = MAY_I_SET.format(branch=branch, shortcode=shortcode)
    return notes, ask, server_commit, server_branch


def check_kind(name):
    """Whether a step's check is a script here, one mg answers itself, or one never run here."""
    if name in NEVER_RUN_IN_A_LOOKUP:   # decided before looking for the file
        return "never"
    if not isinstance(name, str) or not scan.CHECK_NAME.match(name):
        return "none"
    return "script" if os.path.isfile(os.path.join(HERE, "checks", name + ".sh")) else "none"


def standards_mcp():
    return os.environ.get("STANDARDS_MCP") or os.path.expanduser("~/work/rob/standards-mcp")


def check_here(step, facts, top, server_commit, server_branch, run=True):
    """The step's check, run against this checkout in the environment a scan gives it.

    With run=False the same Check is built and nothing is run.
    """
    name = step.get("check")
    kind = check_kind(name)
    if kind == "never":
        return Check("never", "unknown", NEVER_RUN_IN_A_LOOKUP[name], None, "", None)
    if kind == "none":
        return Check("none", None, "", None, "", None)
    script = os.path.join(HERE, "checks", name + ".sh")
    param = step.get("check_param") or ""   # what the scanner passes for none; a check reads ${2:-}
    php_target = scan.php_targets(standards_mcp()).get(os.path.normpath(top))
    site_env = scan.check_environment(facts, dict(os.environ), server_commit, server_branch, php_target)
    if not run:
        return Check("script", None, "", script, param, site_env)
    outcome, line = scan.run_check(script, top, param, site_env)
    return Check("script", outcome, line, script, param, site_env)


def scan_reminder(shortcode, facts):
    """The last words once a step is done or found done: what Rob runs after he deploys."""
    test = facts.get("test")
    where = ""
    if isinstance(test, dict) and not facts.get("upgrade_branch"):
        where = f" to `{test.get('domain')}`"
    return SCAN_REMINDER.format(where=where, shortcode=shortcode)


def step_model(mg_repo, steps_commit, standard, slug):
    """The model the step names, else its track's, in the step files at the
    commit mg last scanned; None when that commit names none.

    Nothing from mg reaches git as anything but a full hash or a plain name.
    Python reads only the one line: StepTree on mg is the judge of a step file.
    """
    if not mg_repo or not all(isinstance(value, str) for value in (steps_commit, standard, slug)):
        return None
    if not scan.server_facts.SHA.match(steps_commit) or not STEP_NAME.match(standard) or not STEP_NAME.match(slug):
        return None
    prefix = STEPS_DIR + standard + "/"
    code, listing = scan.git(mg_repo, "ls-tree", "-r", "--name-only", steps_commit, "--", prefix)
    if code != 0:   # this checkout does not have the commit
        return None

    def model_in(path):
        code, content = scan.git(mg_repo, "show", f"{steps_commit}:{path}")
        model = scan.frontmatter(content).get("model") if code == 0 else None
        return model if model in MODELS else None

    for path in listing.splitlines():
        parts = path[len(prefix):].split("/")
        if not path.startswith(prefix) or len(parts) != 2 or not parts[1].endswith(".md"):
            continue
        stem = parts[1][:-3]
        if stem.startswith("_") or scan.step_name(stem) != slug:
            continue
        return model_in(path) or model_in(prefix + parts[0] + "/_track.md")
    return None


def check_text(check, top):
    """What the brief and the rules say about the step's check: the command the script ran, and its line."""
    if check.kind == "none":
        return CHECK_IS_MGS
    if check.kind == "never":
        return CHECK_IS_SCANS
    # The site's facts are in the command: it is printed to the caller and never written to a file.
    words = ["env"]
    words += [f"{name}={shlex.quote(value)}" for name, value in sorted(check.site_env.items())
              if name.startswith("SITE_")]
    words += [shlex.quote(check.script), shlex.quote(top)]
    if check.param != "":
        words.append(shlex.quote(check.param))
    just_now = ""
    if check.outcome == "unknown":
        just_now = JUST_COULD_NOT.format(line=check.line)
    elif check.outcome is not None:
        just_now = JUST_SAID.format(line=check.line)
    return CHECK_RUN.format(command=" ".join(words), just_now=just_now).rstrip()


def step_values(site, step, top, check):
    """What fills the template's placeholders, each one a string."""
    served = NO_SWEEP_BRIEF
    if site.get("php_current"):
        served = SERVED_BRIEF.format(php_current=site["php_current"])
        if site.get("test_php"):
            served += TEST_SERVED.format(test_php=site["test_php"])
    values = {name: str(step.get(name) or "") for name in ("track", "title", "slug", "detail", "explanation", "how_to")}
    values.update(shortcode=site["shortcode"], top=top, served=served, check=check_text(check, top))
    return values


def render(values):
    """(brief, rules) from the template beside this script.

    The template has three parts: the step, the rules, and what applies to a
    subagent only. The brief is all three; the rules are the middle one with
    the check after it. Each part is filled in one pass, so nothing in a value
    is read again as a placeholder.
    """
    with open(BRIEF, encoding="utf-8") as handle:
        text = handle.read()
    the_step, rest = text.split(RULES_MARK)
    the_rules, for_a_subagent = rest.split(SUBAGENT_MARK)

    def fill(part):
        return re.sub(r"\{\{([a-z_]+)\}\}", lambda found: values[found.group(1)], part)

    brief = fill(the_step) + fill(the_rules) + fill(for_a_subagent)
    rules = fill(the_rules).rstrip() + "\n\n" + CHECK_HEADING + "\n\n" + values["check"]
    return brief.rstrip(), rules.rstrip()


def lookup(options, cwd):
    top, relative = checkout(cwd)
    site, facts, steps_commit = site_or_stop(ask_mg(options, relative))
    shortcode = site["shortcode"]
    lines = []
    if site.get("skipped_reason"):
        lines.append(FROM_SCAN.format(last_checked_utc=site.get("last_checked_utc"),
                                      skipped_reason=site["skipped_reason"]))
    step = site["open_steps"][0]   # mg chooses the step: the first, and never any other

    if step.get("whose_move") == "rob":
        lines.append(YOURS.format(shortcode=shortcode, title=step.get("title"), detail=step.get("detail")))
        lines.append(step.get("how_to") or "")
        # The rules are for a taste step, which the session does once Rob has
        # picked. Its check is not run now; the server is asked only when the
        # check is a script here, for the environment its command carries.
        server_commit, server_branch = None, None
        if check_kind(step.get("check")) == "script":
            _, _, server_commit, server_branch = second_line(site, facts, top, options)
        check = check_here(step, facts, top, server_commit, server_branch, run=False)
        return {"outcome": "say", "say": "\n\n".join(lines), "rules": render(step_values(site, step, top, check))[1]}

    lines.append(step_line(site, step))
    notes, ask, server_commit, server_branch = second_line(site, facts, top, options)
    lines += notes
    if ask is not None:
        return {"outcome": "ask", "say": "\n".join(lines), "ask": ask}

    check = check_here(step, facts, top, server_commit, server_branch)
    if check.outcome == "pass":
        lines.append(ALREADY_DONE)
        return {"outcome": "say", "say": "\n".join(lines), "after": scan_reminder(shortcode, facts)}
    if check.outcome == "unknown":
        lines.append(COULD_NOT_TELL.format(line=check.line))

    mg_repo = scan.git(HERE, "rev-parse", "--show-toplevel")[1].strip()
    model = step_model(mg_repo, steps_commit, site.get("standard"), step.get("slug"))
    if model is None:   # never run on a guess
        lines.append(NO_MODEL.format(title=step.get("title")))
        return {"outcome": "say", "say": "\n".join(lines)}
    brief, rules = render(step_values(site, step, top, check))
    base = local_head(top)[0]
    if model in SUBAGENT_MODELS:
        return {"outcome": "dispatch", "say": "\n".join(lines), "model": model, "brief": brief, "base": base}
    if options.session_model and MODELS.index(options.session_model) < MODELS.index(model):
        lines.append(ASKS_FOR.format(model=model, session=options.session_model))
    return {"outcome": "do", "say": "\n".join(lines), "model": model, "rules": rules, "base": base}


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
