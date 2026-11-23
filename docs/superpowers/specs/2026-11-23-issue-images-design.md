# Images on issues

2026-11-23. Full design. Decisions marked **(Rob)** were made by Rob in
conversation; those marked **(my call)** are mgClaude's and open to change.

## Why

The TSWA project (project 48) is a full app rewrite. Issue #890 has an agent
on Lemur 13 take a screenshot of every page of the old app, and nine build
issues say "compare against the #890 screenshots". Today those pictures can
only live in a local folder: mg renders `![alt](url)` in descriptions and
comments, but has no way to hold a file. Rob wants the pictures on the issue.

## Goals

- An agent on Lemur 13 uploads a folder of pictures to an issue in one
  command. **(Rob)**
- Rob adds pictures to an issue from a browser, phone included. **(Rob)**
- An issue page shows that issue's pictures in a strip, with no markdown
  needed; a project page shows every picture of the project. **(Rob)**
- Any picture can be embedded in any description or comment with ordinary
  markdown. **(Rob)**
- Files live only on the server: not in git, not in the database. **(Rob)**
- A picture may belong to several issues, in the same project or others.
  **(Rob)**
- Paths read well and keep their year, for looking back later. **(Rob)**

Not goals (this round):

- Upload through the HTTP API or MCP (agents off Lemur 13, phone Claude).
  DreamHost's WAF makes that its own piece of work.
- Agents reading pictures through the API. Agents on Lemur 13 have the
  local files.
- Generated thumbnails, captions, hand ordering, cropping, editing.
- Files that are not pictures (PDF, video, zip).
- Pictures attached to a project with no issue.
- Pictures on inbox messages, journal entries or exterm notes.

Would be a failure:

- A picture readable by someone who cannot read any issue it is on.
- A file under `wwwroot/`, or anywhere a git command could remove it.
- An upload that lets anything but a picture be stored or served.
- A project rename that breaks a link already written into markdown.

## Approaches

1. **The folder is the record.** The strip is a directory listing; no
   table. Simplest, files and record cannot disagree, but no "uploaded by",
   and a file cannot belong to two issues.
2. **A table plus files on disk (chosen, Rob).** One row per file, a link
   table for the issues it shows on. Gives attribution and many-to-many.
   Costs a migration, and rows and files can drift if someone edits the
   directory by hand.
3. **Pictures in the database.** Dropped: bloats backups, and there is no
   path on disk to browse.

## The design

### Where a file lives

On disk, under a directory beside the checkout, never inside it **(my
call)**:

    <image_store>/projects/<project_id>/<yyyy>/issues/<issue_id>/<filename>
    /home/<user>/mg_files/images/projects/48/2026/issues/890/01-weather-top.png

- `<image_store>` is a new `Config` property (`image_store_path`), added to
  `ConfigSample.php`; Rob sets it in the server's `Config.php`. Empty means
  the feature is off: no strip, no upload form, the serving script answers
  404. The code creates directories below it as needed (0700 directories,
  0600 files).
- `<project_id>` and `<issue_id>` are the issue the file was first uploaded
  to: its **home**. A file never moves and is never copied, whatever issues
  it is later linked to.
- `<yyyy>` is the year of the upload in UTC **(my call; Rob chose "a year,
  for history")**. An issue that runs over New Year has pictures under two
  years.
- The segment order is Rob's: project, year, then `issues/<id>`.

In a URL:

    /images/projects/48-TSWA/2026/issues/890/01-weather-top.png

- The project segment is `<project_id>`, optionally followed by `-` and
  anything; only the leading digits are read **(Rob's idea)**. mg writes
  the current project name there, reduced to letters, digits, `.`, `_` and
  `-` (other runs of characters become one `-`; nothing left means the bare
  id). A link written before a rename keeps the old name and still works.
- One rule in `wwwroot/.htaccess` sends `^images/projects/` to a serving
  script. Nothing named `images` exists under `wwwroot/`.

### Tables (migration `56_issue_images`)

`issue_images`, one row per file on disk:

| column | |
|---|---|
| `issue_image_id` | primary key |
| `home_project_id`, `home_issue_id`, `home_year` | the path; plain numbers, **no foreign key**, so deleting an issue never deletes this row or the file |
| `filename` | as stored, at most 100 characters |
| `mime_type` | one of the four allowed types |
| `byte_size`, `width`, `height` | |
| `sha256` | of the file's bytes |
| `uploaded_by_aiu` | the actor who uploaded |
| `uploaded_at_utc` | one value for a whole batch |

Unique on (`home_issue_id`, `home_year`, `filename`).

`issue_image_links`, one row per issue a picture shows on:

| column | |
|---|---|
| `issue_image_link_id` | primary key |
| `issue_image_id` | foreign key, cascade |
| `issue_id` | foreign key, cascade |
| `linked_by_aiu`, `linked_at_utc` | |

Unique on (`issue_image_id`, `issue_id`). An upload writes the picture row
and its first link (to the home issue) in one transaction. A picture's
projects are the projects of its linked issues; there is no project link.

Primary key names are unique across the project, as everywhere in mg. Rob
applies the migration by hand; the generated row classes
(`tools/generate-row-classes.php`) need it applied first.

### Who may see a picture

A caller may see a picture when they can read at least one issue it is
linked to, by the same rule the issue page uses (`IssueService`, the
account's human actor, admin or paid). The URL's project and issue must
also match the picture's home. Every other case (not logged in, no such
file, no readable link, feature off) is the same plain 404, so the script
never says whether a file exists.

Consequence, accepted: a picture embedded by markdown in an issue of
another project shows as a broken image to someone who can read that issue
but none of the picture's linked issues. Linking it to that issue fixes it.
Embedding by markdown never creates a link **(my call)**.

A picture with no links left is seen by nobody; the file stays on disk.

### Serving

The serving script includes `prepend.php` like any page, finds the row from
the URL, checks access, and sends the file with the stored `mime_type`,
`Content-Length`, and `Cache-Control: private, max-age=86400`. It serves
only rows in `issue_images`, only with one of the four stored types, and
only a path it builds itself from the row (never from the URL text).
`X-Content-Type-Options: nosniff` is already set site-wide.

### What may be uploaded

One class holds the checks, and both ways in use it:

- **Type:** PNG, JPEG, GIF or WebP, decided by the file's content (finfo
  and `getimagesize` must agree; both extensions are on the server). No
  SVG. The stored extension is made to match the type (`.png`, `.jpg`,
  `.gif`, `.webp`).
- **Size:** at most 10 MB a file **(my call)**. A Pixel 7a PNG screenshot
  is 1 to 3 MB.
- **Name:** letters, digits, `.`, `_`, `-`; any other run of characters
  becomes one `-`; it must start with a letter or digit; at most 100
  characters. Case is kept. A name with nothing usable left becomes
  `image`.
- **Same name again** in the same issue and year: identical bytes (same
  `sha256`) store nothing new and report "already there", so re-running a
  batch is harmless. If that picture had been removed from the issue, the
  upload links it again and reports "linked again": uploading to an issue
  means wanting it there **(my call)**. Different bytes get `-2`, `-3`,
  ... before the extension.
- **Who:** the uploader needs write access to the issue's project. This
  holds for both ways in, the actor named by `--as` included: it is the
  ordinary `project_members` check, not a way round it.
- `fileinfo` joins `Startup\RequiredExtensions` and `composer.json`.

### Way in 1: from Lemur 13

    tools/issue-images/upload.sh [--dry-run] [--as=<aiu_id>] <issue_id> <file-or-dir> ...

- Sends the files over `ssh mg` to `tools/issue_images.php import`, which
  runs the checks and stores them, the way a scan is imported. How the
  bytes travel (a stream, or a temporary directory on the server that the
  importer empties) is the builder's choice; a failed run leaves nothing
  half-stored and nothing in a temporary place.
- A directory means the picture files directly in it, not its
  subdirectories; other files in it are named and skipped.
- The whole run shares one `uploaded_at_utc`.
- `--as` names the uploading actor; without it, the uploader is the human
  actor of the project's account. The flag is trusted for who it says is
  uploading, since anyone who can `ssh mg` already has everything; that
  actor still needs write access to the project **(my call)**.
- Output: one line per file (stored, already there, or refused with the
  reason), then the markdown line for each stored file, ready to paste
  into another issue. Exit 1 if any file was refused.
- `--dry-run` runs every check and stores nothing.

    tools/issue-images/link.sh   <issue_image_id> <issue_id>
    tools/issue-images/unlink.sh <issue_image_id> <issue_id>
    tools/issue-images/purge.sh  [--dry-run]

Linking to another issue is command-line only this round **(my call)**.
`link.sh` and `unlink.sh` take the same `--as`, with the same default and
the same write check on the target issue's project. A link never crosses
accounts: the target issue's project must belong to the same account as
the picture's home project, or the link is refused **(my call)**; a link
is what lets someone see a picture.
`purge.sh` deletes the row and the file of every picture with no links; it
is the only thing that ever deletes a file.

### Way in 2: from the browser

In the issue page's Images card, for someone with write access: a file
input (`multiple`, `accept="image/*"`) and an Add images button, posting
to a new `wwwroot/issues/images.php` with the CSRF field. The post shares
one `uploaded_at_utc`, the uploader is the account's human actor, and the
page comes back to `#images` with a line per file that was refused or
already there.

Limits **(my call)**: 20 files a post (PHP's `max_file_uploads`); larger
sets go by the script. The form checks count and size in the browser
before posting and says which file is too big. A post that still exceeds
PHP's `post_max_size` arrives empty and gets the existing CSRF 403; the
CSRF gate in `prepend.php` is not touched for this. The builder's first
step is to read web PHP 8.5's `upload_max_filesize` and `post_max_size`
on the server (CLI PHP 8.5 says 512M for both, but web can differ). If
web allows less than 10 MB a file, raising it becomes one of Rob's deploy
steps; the form's total-size check is set to what web allows.

### The strips

On the issue page, a card below the description and above Sub-issues:

    Images (21)                                    [ Choose files ] [ Add images ]
    ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌──
    │        │ │        │ │        │ │        │ │      scrolls sideways →
    └────────┘ └────────┘ └────────┘ └────────┘ └──
    01-weather-top  02-weather-hourly  03-weather-10day  04-details
    [copy] [remove] [copy] [remove]    [copy] [remove]   [copy] [remove]

- Shows the pictures linked to this issue. **(Rob)**
- Order: newest upload on the left **(Rob)**; within one batch, by
  filename, so `01-` is left of `02-` **(my call)**. Sorted on the
  picture's upload time, not the time it was linked.
- One row that scrolls sideways when it is wider than the page. **(Rob)**
- Each picture is the full file shown at a fixed height (about 160px) with
  `loading="lazy"`, `width` and `height` from the row, and its filename as
  alt text. Tapping it opens the full file in a new tab.
- **copy** puts the markdown line on the clipboard:
  `![01-weather-top](/images/projects/48-TSWA/2026/issues/890/01-weather-top.png)`
- **remove** (write access, with a confirm) unlinks the picture from this
  issue. The file stays on disk.
- With no pictures, a reader sees no card; a writer sees the card with
  only the upload form.

On the project page, the same strip with every picture linked to any issue
of the project, each once, labelled with its filename and a link to its
home issue (`#890`). Newest 100 **(my call)**; no upload form and no
remove there.

Pictures embedded by markdown in a description or comment never spill out
of the page (`max-width: 100%`).

## Shipping

One bubble on branch `issue-images` (opened at 0.86.0). Rob's steps at
deploy: create the directory, set `image_store_path` in the server's
`Config.php`, apply migration 56. The migration is needed mid-bubble: the
row classes are generated from the server's schema, so the builder stops
after writing the migration and asks Rob to apply it. First real use: upload
`~/work/rob/tswa/TSWA-reference/` to #890.

## Left to the builder

- Class names and their split, following `Issues\` (store, service,
  commands) and the row-class generator.
- The transport inside `upload.sh`.
- Markup and CSS of the strip, within the sketch above and the site's
  existing card styles, dark mode included.
- Unit tests (hermetic: sqlite and a temporary directory) for the name
  rule, the type check, the same-name rule, URL parsing and building, the
  access rule and the strip order.
- A short section in `CLAUDE.md`.
