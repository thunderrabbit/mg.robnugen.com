You are doing one step of mg's site standards for the site `{{shortcode}}`, in the checkout `{{top}}`.

## The step

- Track: {{track}}
- Step: **{{title}}** (its name on mg is `{{slug}}`)
- Where it stands: {{detail}}
- PHP: {{served}}

### Why this step exists

{{explanation}}

### How to do it

{{how_to}}

### The check that decides it

{{check}}

<!-- rules -->
## Rules for this step

1. **Compare before you change.** Read the explanation and the how-to, then
   compare them carefully with this repo's code before changing anything:
   what is already there, what is missing, and what the how-to assumes that
   this repo does differently.
2. **One step only.** Never go on to a second step. Never mark a step done:
   only the scanner ticks a step, from the code the server runs, after Rob
   deploys.
3. **If the step does not fit this site, do not decide alone.** Change
   nothing. Say why, and suggest that Rob mark it NAh: an issue titled
   `{{slug}}.{{shortcode}}` in the site's mg project, set to status NAh.
4. **Work under this project's own rules.** Its `CLAUDE.md`, its project
   memory and the voice of its `git log` decide the branch, the BEGIN commit,
   the bubble size, the commit format and the gates. Never commit on, check
   out or merge into the trunk.
5. **Open a bubble when the project's rules call for one, and leave it
   open.** Never run `git close-bubble`. Never push.
6. **Before each commit, if the repo has a `rector.php`, run its dry run:**
   `~/work/rob/standards-mcp/run-rector.sh {{top}}` (exit 2 means it would
   change something). If Rector would rewrite code the how-to gave word for
   word, commit Rector's form in the same commit, never as a follow-up, and
   say which line differs from the how-to. "Exactly as written" in a how-to
   protects the SQL, the signatures, the docblocks and the error handling;
   the syntax follows the site's Rector level. One site needed two commits
   for a single how-to because it followed the how-to's parentheses to the
   letter.
7. **Finish by running the check above and the project's gates**, each of
   these that the project is set up for, and note each command's exit code:

       ~/work/rob/standards-mcp/run-unit.sh {{top}}
       ~/work/rob/standards-mcp/run-phpcs.sh {{top}}
       ~/work/rob/standards-mcp/run-phpstan.sh {{top}}

8. **Plain words.** Every site here is working; an open step is the next
   thing to do. No warning or alarm wording in commits or in what you report.

<!-- subagent -->
## Because you are a subagent

Nobody can answer a question while you work, and the session that started you
will check what you did for itself.

- You were given this project's `CLAUDE.md` and its memory index, but not the
  memory files. Before your first commit, open every memory file the index
  links about commits, bubbles, branches and gates, and read `git log`.
- Wherever a project rule says "ask Rob" (a bubble of exactly three commits,
  where to branch, a step big enough to need a plan), stop before any commit
  and report **needs Rob**. You cannot talk to him.
- No hook tells you the time. Give a time only as an absolute timestamp you
  read from a command's output, or not at all.

End with exactly one of these three reports, its name alone on the first line:

- **done**: what changed; each commit's hash and subject; the check's line;
  each gate command you ran and its exit code.
- **does not fit**: why, and the NAh issue title from rule 3. Nothing was
  changed.
- **needs Rob**: one question, with your proposal, asked before any commit.
