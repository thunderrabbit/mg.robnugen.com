# A step's position comes from its filename

2026-11-13. Short design, approved in conversation by Rob.

## Why

Rob renamed all 69 step files so a directory listing shows them in the order
they are applied: `030_autoloader.md`, `038.9996_remove_unused_print_robs.md`.
Today a step's name is its whole filename, so the renames give every step a
new name. That would orphan what mg holds under the old names (78 step rows,
1,113 stored results, 11 note issues), and the dotted names are refused
outright, which rejects the whole tree.

## Goals

- Rob's filenames stay exactly as he wrote them.
- A dotted number (`038.9996_`) is legal.
- Every step keeps the name it had before the renames, so nothing stored is
  orphaned and nothing needs reuniting.
- The order lives in one place: the filename. A reorder is a rename.

Not goals: renaming tracks or standards (`_track.md` keeps its `position:`
line; directories carry no number), and any change to the page.

Would be a failure: a database migration, renamed issues, or a step whose
name changes the next time Rob renumbers it.

## Approach

1. **The number is the position, the rest is the name (chosen).**
   `038.9996_remove_unused_print_robs.md` is position 38.9996, step
   `remove_unused_print_robs`. Names never change, so nothing is orphaned.

## The design

**Filename.** A step file is `<number>_<name>.md`.

- `<number>` is digits, optionally a dot and one to four more digits
  (`010`, `25`, `038.9996`). Leading zeros mean nothing. Its value is the
  position, from 0 up to but not including 65536. **0 is allowed**
  (`000_phpstan_level_0.md`); today's floor is 1.
- `<name>` is the step name: a lowercase letter, then lowercase letters,
  digits and underscores, 64 characters at most. It starts with a letter (my
  call: today a name may start with a digit, no step does, and a letter makes
  the split from the number unambiguous).
- The split is at the first underscore after the number.
- A step file with no number is refused, naming the file. So is one whose
  number is out of range or has more than four decimals.

**No `position:` in a step file.** The line comes out of all 69 files, and a
step file that still has one is refused, so the two can never disagree.
`000_phpstan_level_0.md` says `position: 0.1` today; it becomes 0 like its
filename. Every other file already agrees with its number.

**Names stay unique.** Two files that differ only in number are the same step
name, and the existing "already used by" refusal catches them, in one track
or across tracks.

**Position 0 and the database.** Position is a plain `DECIMAL(9,4) NOT NULL`
column on `std_steps` and `std_tracks`. It is in no key: the primary keys are
the auto-increment ids and the unique keys are `(std_track_id, slug)` and
`(std_standard_id, slug)`. It is only ever written and sorted on
(`ORDER BY t.position, t.slug, s.position, s.slug`). So 0 is an ordinary
value there. The builder confirms no PHP treats a position as true/false.

**Who reads filenames.** Three places must agree on the split:

- `Inventory\Standards\Names`: the step-name pattern (letter first), and one
  function that turns a filename stem into position and name. Note titles
  keep the form `<step>.<shortcode>`.
- `Inventory\Standards\StepTree`: takes position and name from that function
  instead of from frontmatter and the whole stem.
- `tools/site-standards/scan.py`: sends the step name without the number, by
  the same rule. It does not use positions.

Anything else that builds a step file's path from its name (tests, checks,
docs, the `/next-mg-step` skill) is found by grep and fixed the same way.

**What mg sees on the first scan after deploy.** The same 78 names. Rows are
updated in place, positions refreshed, one position changes (0.1 to 0),
nothing is retired, no result moves. Checking that on a dry run
(`scan.sh --dry-run`, `steps_retired` = 0) is the acceptance test.

**Deploy.** Code and renamed files go out together on this branch. Old code
with new files refuses the tree; new code with old files refuses every
unnumbered file. The scanner already requires its scripts to match the
deployed commit, so it cannot run half of each.

## Commits (bubble `position-from-filename`, BEGIN at 0.83.0)

1. This design.
2. `Names`: filename split and letter-first step names, with unit tests.
3. `StepTree`: position and name from the filename; refuse `position:` and
   unnumbered files; fixtures and tests.
4. `scan.py`: strip the number; Python tests.
5. Rob's 69 renames, as pure renames.
6. Remove `position:` from the 69 files.
7. CLAUDE.md: how a step file is named.

Commits 2 to 4 leave the real tree unreadable until 5 and 6 land; that is
inside the bubble and never deployed.

## Left to the builder

- Where the split function lives and what it returns.
- Exact wording of the refusals.
- Whether any test reads the real step files and needs the new names.
