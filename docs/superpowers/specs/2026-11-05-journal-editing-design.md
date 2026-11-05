# Journal: edit recent entries on mg

Date: 5 Nov 2026. Rob, with Claude (brainstormed from the sitnow session).
Mockups: https://claude.ai/artifact/PYBVAtFsVpWE5KynZFAPJg (private to Rob).

## Intent

Rob's journal and dream entries land as Markdown files on Lemur 13, his travelling
laptop. Until he is back at Lemur 13 he cannot touch them. On a bus with spare time,
he wants to open a recent entry on his phone, read what Carrie wrote, and fix it:
wrong words from transcription, things remembered later, parts to cut, a better title
or tags. The edit should then land on Lemur 13 by itself.

Rob reviews, commits and deploys (`bbfj`) the journal himself, as now. This feature
only lets him edit sooner and from anywhere.

## How entries arrive today (unchanged)

- BG Bells (sitnow) records → DreamIntake (aiu 68) posts to jikan → CarriedAway
  (aiu 57) for journals → Carrie (aiu 9) writes the file.
- Google Docs → HairBrush (aiu 58) → CarriedAway → Carrie.
- Plain jikan inbox messages → Carrie.

Carrie runs hourly from cron (`30 * * * * ~/work/carrie/run.sh`) and writes to
`~/work/rob/robnugen.com/journal/journal/YYYY/MM/DD<slug>.md`. That directory is its
own git repo (branch `master`). She never commits or deploys. Dream files are named
`DDdream-<slug>.md`. Journals may be appended into an existing file for that day.

Every one of these paths has Lemur 13 fetching from mg; mg never reaches Lemur 13.
This design keeps that: **all syncing is started by Lemur 13**.

## Goals

1. Every **eligible** journal file is on mg, where Rob can list and edit it after
   logging in.
2. Rob edits the **whole file**, frontmatter included, as plain text.
3. A saved edit is written to Lemur 13 at the same path, **verbatim**. Nothing
   re-parses it: CarriedAway and Carrie rebuild text and frontmatter and would undo
   the edit.
4. Files that are not yet committed can be **renamed**: both the `YYYY/MM/DD` date and
   the slug.
5. Files leave mg once they are committed and their entry date is more than 14 days
   old.

## Non-goals

- Changing BG Bells. It keeps filing exactly as it does.
- Editing jikan inbox messages, Google Docs, or anything upstream of Carrie.
- Committing, pushing or deploying the journal. Rob does that by hand.
- A rich editor, preview, or formatting buttons.
- Other mg users. Rob only.
- Designing a full merge for the rare "edited in both places" case (see Conflicts).

## Anti-goals

- **Losing either version** of a file, ever, silently.
- Writing a file with broken frontmatter to the journal.
- A key on Lemur 13 or a page on mg that can reach anything of Rob's beyond these
  journal files.
- Journal text served to anyone but Rob. These are private entries, dreams included.
  (Stored unencrypted in mg's database, by Rob's choice: HTTPS plus his login is
  enough, matching the jikan inbox.)

## Eligibility

Only entry files count: paths matching `YYYY/MM/DD*.md` in the journal repo. Legacy
`.html` entries, `.comment` files and scripts are never uploaded.

An entry file is eligible when **either**:

- it has uncommitted changes (untracked, or tracked and modified; use
  `git status --porcelain -uall` so new directories like `?? 2026/11/` expand to
  files), **or**
- the date in its path (`YYYY/MM/DD`) is within the last 14 days.

Commit age is deliberately ignored. Rob's bulk edits touch thousands of old files in
one commit (4,452 files in the 16 commits up to 5 Nov 2026), and none of those should
reach mg.

A file stops being eligible, and is removed from mg, when it has no uncommitted
changes, its path date is more than 14 days old, **and** mg holds no edit from Rob
waiting to go back. A waiting edit is never removed.

## The sync (Lemur 13)

One script, run **at the end of Carrie's `run.sh`** so it starts as soon as she
finishes, which is within a minute of her writing files. (Rob asked for "within a
minute after Carrie finishes getting files".) It runs every time, even if Carrie had
nothing to do, because it is also how Rob's edits come back.

Each run, in this order:

1. **Fetch edits.** Ask mg for every file with an edit from Rob waiting. For each:
   1. Validate it again (same rules as mg's Save, below). If it fails, leave the
      Lemur 13 file untouched and report the problem back to mg.
   2. If the Lemur 13 file still matches the **base** (the content Lemur 13 last
      uploaded for that path), write the edit. If the edit renames the file, move it
      first, and refuse if the target path already exists.
   3. If the Lemur 13 file has changed since the base, **do not overwrite it**. Save
      the edit beside it as `<file>.from-mg` and tell mg it is a conflict. (`.from-mg`
      files must be ignored by the journal's Perl parser and by Carrie's dedup glob.
      The builder confirms this or picks a suffix that is ignored.)
   4. Tell mg the outcome. On success the edit becomes the new base and the waiting
      flag clears.
2. **Upload.** For each eligible file whose content differs from mg's copy, upload it
   as the new base. If mg holds a waiting edit for that path, mg keeps the edit and
   marks the file as a conflict. It does not discard the edit.
3. **Remove.** Tell mg which paths are no longer eligible. A file deleted on Lemur 13
   is removed from mg too, unless an edit is waiting, in which case it is flagged as
   a conflict.

Renames only apply to **untracked** files (see Renaming), so the script never needs to
`git mv` and never touches git state. It only reads git (`git status`, `git log`).

The script must never block or break Carrie's run. A failure (mg unreachable, laptop
offline) is logged and the next run retries.

## The page on mg

`https://mg.robnugen.com/journal`, behind Rob's normal mg login. Phone-first.

**List.** Newest first, grouped by the entry's date. Each row: the title (from
frontmatter), the path, and one status label:

| Label | Meaning |
| --- | --- |
| Not committed | Lemur 13 has uncommitted changes to it |
| Committed · leaves 17 Nov | Committed; leaves mg 14 days after the entry's date |
| Your edit · waiting for Lemur 13 | Rob saved; not yet fetched |
| Changed in both places | Conflict; see Conflicts |
| Couldn't be applied | Lemur 13's second validation refused it; shows why |

The page shows when Lemur 13 last synced.

**Editor.** The whole file in one plain monospace text box, the path above it, Save
and Cancel. For files that are not committed, a separate **filename** box shows the
editable part of the path (`YYYY/MM/DD` and slug). Changing the title suggests a new
slug, which Rob can accept or ignore. It never renames by itself.

Saving again before Lemur 13 fetches replaces the waiting edit.

## Validation (on Save, and again on Lemur 13)

Save is **refused** with a message naming the problem, and the text stays in the box,
when:

- the file does not start with a `---` line followed by a closing `---` line;
- the frontmatter does not parse;
- `title`, `date` or `tags` is missing;
- `date` is not in the form `YYYY-MM-DDTHH:MM:SS+HH:MM`;
- **the date in the filename (`YYYY/MM/DD`) does not equal the date part of the
  frontmatter `date`** (Rob: "MUST match"). This applies to every save. For a file that
  cannot be renamed, that means its frontmatter date cannot move to another day.
- a rename targets a path already on mg, or one that is malformed (`YYYY/MM/DD` must be
  a real date; the slug must be non-empty and filename-safe).

**Claude's call: refuse only new breakage.** Some older entries have a date-only
`date:` (`2024-03-16`) or no `tags:` line. If the file as uploaded already lacked a
time or tags, that is accepted on save. Everything else above is still refused. With
the 14-day window this mostly affects nothing, because Carrie's files are well-formed.

mg has no YAML library today (`composer.json` requires only extensions). Either add one,
or write a strict checker for the subset the journal uses. Builder's choice. The
Lemur 13 re-check should use the same rules, so the two cannot drift. Ideally it is the
same code, or the two are tested against shared fixtures.

## Renaming

- Only for files git has **never tracked**. Nothing outside Lemur 13 has seen their
  name yet: not dreams.robnugen.com, which remembers dreams by path, and not
  robnugen.com, where a deployed filename becomes a public URL.
- Both the `YYYY/MM/DD` date and the slug can change (Rob prefers being able to fix
  the date). The frontmatter date must match the new date (see Validation).
- **Claude's call:** dropping the word "dream" from a dream file's name shows a
  warning but still saves. dreams.robnugen.com finds dreams by that word.

## Conflicts

"Changed in both places" means Rob saved on mg, and before Lemur 13 fetched it the
Lemur 13 copy also changed. That could be Carrie appending a later entry, Rob editing
locally, or a commit. Rob expects this to be rare.

The only rule now: **neither version is lost.** Lemur 13 keeps its own file. Rob's
edit is saved beside it as `.from-mg`, mg shows the label, and Rob merges by hand on
Lemur 13. A better flow can come later. Clearing a conflict is left to the builder;
the simplest is to clear it once `.from-mg` is gone and the file re-uploads.

## Access and security

- **Claude's call:** the mg page uses Rob's existing login session. No new login.
- **Claude's call:** Lemur 13 authenticates with its **own mg key**, scoped to the
  journal sync endpoints only, in the style of the `todos_limited` actors and
  `Auth\TodoGrants`. It is not the jikan key Carrie uses. It lives in a chmod-600
  file on Lemur 13, never in a repo.
- All traffic is HTTPS. The page and API are Rob-only.
- This touches auth (a new scoped key) and stores private text, so it is
  **security-sensitive**: Rob reviews the diff before it ships.

## Left to the builder

- Table design, endpoint shapes, and how "base" is recorded (a content hash is
  enough).
- The sync script's language. **Claude's call on where it lives:** its source is in
  this repo under `tools/`, versioned with the API it talks to. Carrie's `run.sh` runs a
  **pinned installed copy** beside it in `~/work/carrie/`, never the mg checkout
  directly. The mg checkout is often on a feature branch, and a half-built script must
  not run hourly against private files. Installing or updating the copy is a deliberate
  step.
- How the list handles a long backlog.
- Exact wording of messages, following the mockups.
