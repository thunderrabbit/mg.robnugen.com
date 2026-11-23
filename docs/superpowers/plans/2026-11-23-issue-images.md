# Images on Issues Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pictures can be uploaded to an mg issue (a script from Lemur 13, a form in the browser), are kept on the server's disk outside git and the web root, and show in a strip on the issue and project pages.

**Architecture:** A new `Issues\Images\` namespace. Pure value classes do the checks (type, name, path). `PdoImageStore` reads and writes two new tables, `ImageFiles` owns the disk, and `ImageService` holds every rule and leans on the existing `IssueService` for who may read or write an issue. The browser form, the serving script and the command-line tool are thin callers of `ImageService`.

**Tech Stack:** PHP 8.5, PDO (MySQL in production, SQLite in unit tests), Apache `.htaccess`, bash + ssh for the Lemur 13 side, Codeception Unit suite.

**Spec:** `docs/superpowers/specs/2026-11-23-issue-images-design.md`

## Global Constraints

- Branch `issue-images`, one bubble opened at 0.86.0; never commit on master, never push.
- Allowed types: PNG, JPEG, GIF, WebP only, decided by content. No SVG.
- At most 10 MB (10 * 1024 * 1024 bytes) a file; at most 20 files a browser post.
- Filenames: letters, digits, `.`, `_`, `-`; start with a letter or digit; at most 100 characters; case kept.
- Disk: `<image_store_path>/projects/<project_id>/<yyyy>/issues/<issue_id>/<filename>`, directories 0700, files 0600. `<yyyy>` is the upload year in UTC.
- URL: `/images/projects/<project_id>[-<anything>]/<yyyy>/issues/<issue_id>/<filename>`.
- Empty `image_store_path` means the feature is off everywhere.
- Every refusal by the serving script is the same bare 404.
- Primary key column names are unique across the project.
- Classes under `classes/` are `declare(strict_types=1)`, `final readonly` where they can be, PSR-12, PHPStan-clean at the level in `phpstan.neon` with no ignores.
- The repo is public: tests and comments use made-up names, never real project names.
- Gates before any task is called done: `run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh` (see `CLAUDE.md`). Commits use multiple `-m` flags, never a heredoc, and end with the `Co-Authored-By` line.
- Commit granularity: one layer per commit (schema, class + its test, wiring, template).

## Review Focus

1. A URL whose project or issue segment does not match the picture's home (`/images/projects/7/2026/issues/999/a.png` for a picture homed in issue 12) must 404, not serve. Test in Task 5.
2. A file named `shot.png` whose bytes are HTML or SVG must be refused, and a JPEG named `shot.png` is stored as `shot.jpg`. Tests in Task 2.
3. A filename made of path pieces (`../../x.png`, `a/b.png`, `.htaccess`, `x.php.png`) must end up a harmless single name inside the issue's directory. Tests in Task 2.
4. A reader without write access must not upload, remove or link; a caller with no readable linked issue must not see the picture, including after its only readable link is removed. Tests in Task 5.
5. A second upload of the same batch stores nothing and leaves one file per picture on disk; a failed batch leaves no stray file. Tests in Task 5.

---

### Task 1: Schema, config, extension, row classes

**Files:**
- Create: `db_schemas/56_issue_images/a_create_issue_images.sql`, `db_schemas/56_issue_images/b_create_issue_image_links.sql`
- Modify: `classes/ConfigSample.php`, `classes/Startup/RequiredExtensions.php`, `composer.json`, `Tests/Unit/RequiredExtensionsTest.php` (if it pins the list)
- Create (generated): `classes/Database/Row/IssueImageRow.php`, `classes/Database/Row/IssueImageLinkRow.php`

**Interfaces:**
- Produces: `\Config::$image_store_path` (string, default `''`); `Database\Row\IssueImageRow` with `issueImageId, homeProjectId, homeIssueId, homeYear, filename, mimeType, byteSize, width, height, sha256, uploadedByAiu, uploadedAtUtc`; `Database\Row\IssueImageLinkRow` with `issueImageLinkId, issueImageId, issueId, linkedByAiu, linkedAtUtc`.

- [ ] **Step 1: Write the two migrations.**

```sql
CREATE TABLE issue_images (
    issue_image_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    home_project_id  INT UNSIGNED NOT NULL,
    home_issue_id    INT UNSIGNED NOT NULL,
    home_year        SMALLINT UNSIGNED NOT NULL,
    filename         VARCHAR(100) NOT NULL,
    mime_type        VARCHAR(20)  NOT NULL,
    byte_size        INT UNSIGNED NOT NULL,
    width            INT UNSIGNED NOT NULL,
    height           INT UNSIGNED NOT NULL,
    sha256           CHAR(64)     NOT NULL,
    uploaded_by_aiu  INT UNSIGNED NOT NULL,
    uploaded_at_utc  DATETIME(6)  NOT NULL,
    UNIQUE KEY uniq_home_file (home_issue_id, home_year, filename),
    INDEX idx_uploaded (uploaded_at_utc),
    FOREIGN KEY (uploaded_by_aiu) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE issue_image_links (
    issue_image_link_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_image_id      INT UNSIGNED NOT NULL,
    issue_id            INT UNSIGNED NOT NULL,
    linked_by_aiu       INT UNSIGNED NOT NULL,
    linked_at_utc       DATETIME(6)  NOT NULL,
    UNIQUE KEY uniq_image_issue (issue_image_id, issue_id),
    INDEX idx_issue (issue_id),
    FOREIGN KEY (issue_image_id) REFERENCES issue_images(issue_image_id) ON DELETE CASCADE,
    FOREIGN KEY (issue_id)       REFERENCES issues(issue_id)             ON DELETE CASCADE,
    FOREIGN KEY (linked_by_aiu)  REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Each file opens with a comment saying what the table is and that `home_*` deliberately carry no foreign key (deleting an issue must not delete the row or the file). Filenames follow the `[a-z]+_create_*.sql` rule.

- [ ] **Step 2: Commit** `Add issue_images and issue_image_links tables`.
- [ ] **Step 3: `ConfigSample.php`** gains `public string $image_store_path = '';` with a comment: absolute directory outside the checkout, empty turns images off. Commit.
- [ ] **Step 4: `fileinfo`** joins `RequiredExtensions::REQUIRED` and `composer.json` (`"ext-fileinfo": "*"`); update the test that compares the two lists. Run the Unit suite. Commit.
- [ ] **Step 5: Row classes.** The generator reads a TSV dumped from the server's `information_schema`; the tables are not applied there yet. Write `columns.tsv` by hand in the scratchpad with the header `TABLE_NAME COLUMN_NAME DATA_TYPE COLUMN_TYPE IS_NULLABLE` and one line per column above exactly as MySQL 8.4 reports them (`int` / `int unsigned`, `smallint` / `smallint unsigned`, `varchar` / `varchar(100)`, `char` / `char(64)`, `datetime` / `datetime(6)`, all `NO`), run the generator in the php8.5 runner for `issue_images issue_image_links`, tidy with phpcbf. Commit `Generate IssueImageRow and IssueImageLinkRow`. After Rob applies migration 56, regenerate from the real dump and confirm `git diff` is empty (Task 8).

### Task 2: The checks (pure classes)

**Files:**
- Create: `classes/Issues/Images/ImageType.php`, `ImageName.php`, `ImageRefused.php`, `CheckedImage.php`, `ImageCheck.php`
- Test: `Tests/Unit/Issues/ImageTypeTest.php`, `ImageNameTest.php`, `ImageCheckTest.php`, support `Tests/Unit/Issues/ImageBytes.php`

**Interfaces:**
- Produces:
  - `enum ImageType: string { Png = 'image/png'; Jpeg = 'image/jpeg'; Gif = 'image/gif'; Webp = 'image/webp'; }` with `extension(): string` (`png`, `jpg`, `gif`, `webp`), `static fromMime(string): ?self`.
  - `ImageName::clean(string $original, ImageType $type): string` and `ImageName::numbered(string $name, int $n): string` (`a.png`, 2 → `a-2.png`).
  - `final class ImageRefused extends \RuntimeException` (message is the reason shown to the user).
  - `final readonly class CheckedImage { string $path; string $name; ImageType $type; int $byteSize; int $width; int $height; string $sha256; }`
  - `ImageCheck::MAX_BYTES = 10485760`; `ImageCheck::file(string $path, string $originalName): CheckedImage` throws `ImageRefused`.
  - Test support `ImageBytes::png(int $seed = 0): string`, `::jpeg()`, `::gif()`, `::webp()` returning real minimal image bytes built with GD (the runner has it) and `::write(string $dir, string $name, string $bytes): string`.

- [ ] **Step 1: Failing tests.** Assertions, with these exact values:

`ImageNameTest`:
```php
$t = ImageType::Png;
$this->assertSame('01-weather-top.png', ImageName::clean('01-weather-top.png', $t));
$this->assertSame('Screenshot_20261123-101500.png', ImageName::clean('Screenshot_20261123-101500.png', $t));
$this->assertSame('my-shot-1.png', ImageName::clean('my shot (1).png', $t));
$this->assertSame('x.png', ImageName::clean('../../x.png', $t));
$this->assertSame('b.png', ImageName::clean('a/b.png', $t));
$this->assertSame('b.png', ImageName::clean('a\\b.png', $t));
$this->assertSame('htaccess.png', ImageName::clean('.htaccess', $t));
$this->assertSame('x.php.png', ImageName::clean('x.php.png', $t));   // harmless: served by row type, never executed
$this->assertSame('shot.jpg', ImageName::clean('shot.png', ImageType::Jpeg));
$this->assertSame('shot.jpg', ImageName::clean('shot.JPEG', ImageType::Jpeg));
$this->assertSame('image.png', ImageName::clean('日本語.png', $t));
$this->assertSame('image.png', ImageName::clean('', $t));
$this->assertSame(100, strlen(ImageName::clean(str_repeat('a', 300) . '.png', $t)));
$this->assertStringEndsWith('.png', ImageName::clean(str_repeat('a', 300) . '.png', $t));
$this->assertSame('a-2.png', ImageName::numbered('a.png', 2));
$this->assertSame(100, strlen(ImageName::numbered(str_repeat('a', 96) . '.png', 12)));
```
Rule for `clean`: take the part after the last `/` or `\`; drop one trailing extension if it is one of `png jpg jpeg gif webp` (any case); replace every run of characters outside `[A-Za-z0-9._-]` with one `-`; trim leading and trailing `.`, `_`, `-`; empty becomes `image`; cut the stem so stem + `.` + extension is at most 100; append the type's extension.

`ImageCheckTest` (files written to a temp dir):
- each of the four types passes and reports its type, 1x1-or-larger width/height, `byteSize === filesize`, `sha256 === hash_file('sha256')`;
- JPEG bytes named `shot.png` → `name === 'shot.jpg'`;
- `<html><script>alert(1)</script>` named `shot.png` → `ImageRefused`, message `not a PNG, JPEG, GIF or WebP picture`;
- `<svg xmlns="http://www.w3.org/2000/svg"/>` named `a.svg` → same refusal;
- PNG bytes followed by padding to `MAX_BYTES + 1` → `ImageRefused`, message `larger than 10 MB`;
- a zero-byte file and a missing path → `ImageRefused`.

- [ ] **Step 2: Run, see them fail.** `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`
- [ ] **Step 3: Implement.** `ImageCheck::file` checks size first, then requires `finfo` (`FILEINFO_MIME_TYPE`) and `getimagesize()['mime']` to name the same allowed type.
- [ ] **Step 4: Run, see them pass; phpcs + PHPStan.**
- [ ] **Step 5: Commit** (`Add Issues\Images checks: type, name, file`).

### Task 3: Paths and URLs

**Files:**
- Create: `classes/Issues/Images/ImagePath.php`
- Test: `Tests/Unit/Issues/ImagePathTest.php`

**Interfaces:**
- Produces: `final readonly class ImagePath { int $projectId; int $year; int $issueId; string $filename; }` with
  - `static fromUrlPath(string $path): ?self`
  - `static ofRow(IssueImageRow $row): self`
  - `relative(): string` → `projects/7/2026/issues/12/a.png`
  - `url(string $projectName): string` → `/images/projects/7-jikan/2026/issues/12/a.png`
  - `static slug(string $projectName): string`
  - `markdown(string $projectName): string` → `![a](/images/projects/7-jikan/2026/issues/12/a.png)` (alt is the filename without its extension)

- [ ] **Step 1: Failing tests.**
```php
$p = ImagePath::fromUrlPath('/images/projects/7-jikan/2026/issues/12/01-top.png');
// projectId 7, year 2026, issueId 12, filename '01-top.png'
$this->assertNotNull(ImagePath::fromUrlPath('/images/projects/7/2026/issues/12/a.png'));
$this->assertNotNull(ImagePath::fromUrlPath('/images/projects/7-old.name_here/2026/issues/12/a.png'));
foreach ([
    '/images/projects/7x/2026/issues/12/a.png',        // name must follow a dash
    '/images/projects/-jikan/2026/issues/12/a.png',
    '/images/projects/0/2026/issues/12/a.png',
    '/images/projects/7/26/issues/12/a.png',
    '/images/projects/7/2026/issues/12/',
    '/images/projects/7/2026/issues/12/a/b.png',
    '/images/projects/7/2026/issues/12/..',
    '/images/projects/7/2026/issues/12/.a.png',
    '/images/projects/7/2026/issues/12/a%2Fb.png',
    '/images/projects/7/2026/issues/12/a.png/extra',
    '/images/projects/7/2026/comments/12/a.png',
    '/other/projects/7/2026/issues/12/a.png',
] as $bad) { $this->assertNull(ImagePath::fromUrlPath($bad), $bad); }
$this->assertSame('jikan', ImagePath::slug('jikan'));
$this->assertSame('bfr-barefoot_rob_master', ImagePath::slug('bfr ~/barefoot_rob_master'));
$this->assertSame('', ImagePath::slug('日本語'));
$this->assertSame('/images/projects/7/2026/issues/12/a.png', $p7->url('日本語'));
$this->assertSame('![01-top](/images/projects/7-jikan/2026/issues/12/01-top.png)', $p->markdown('jikan'));
```
Pattern for `fromUrlPath`: `#^/images/projects/([1-9]\d*)(?:-[^/]*)?/(\d{4})/issues/([1-9]\d*)/([A-Za-z0-9][A-Za-z0-9._-]{0,99})$#`. The caller passes the path part of the request URI, undecoded. `slug`: runs outside `[A-Za-z0-9._-]` become `-`, trim `-` and `.` from both ends.

- [ ] **Steps 2-5:** fail, implement, pass + gates, commit (`Add Issues\Images\ImagePath`).

### Task 4: Store and disk

**Files:**
- Create: `classes/Issues/Images/ImageStore.php` (interface), `PdoImageStore.php`, `ImageFiles.php`, `ProjectImage.php`
- Test: `Tests/Unit/Issues/PdoImageStoreTest.php` (SQLite in memory, like `JournalFilesTest`), `Tests/Unit/Issues/ImageFilesTest.php`, support `Tests/Unit/Issues/ImageSchema.php`

**Interfaces:**
- Produces `interface ImageStore`:
  - `find(int $issueImageId): ?IssueImageRow`
  - `findAtHome(int $homeIssueId, int $homeYear, string $filename): ?IssueImageRow`
  - `findBySha(int $homeIssueId, int $homeYear, string $sha256): list<IssueImageRow>`
  - `insert(int $homeProjectId, int $homeIssueId, int $homeYear, CheckedImage $image, string $filename, int $aiu, \DateTimeImmutable $at): int`
  - `link(int $issueImageId, int $issueId, int $aiu, \DateTimeImmutable $at): bool` (false when already linked)
  - `unlink(int $issueImageId, int $issueId): bool`
  - `linkedIssueIds(int $issueImageId): list<int>`
  - `forIssue(int $issueId): list<IssueImageRow>` (uploaded_at_utc DESC, filename ASC, issue_image_id ASC)
  - `forProject(int $projectId, int $limit): list<ProjectImage>` (each picture once; same order)
  - `unlinked(): list<IssueImageRow>`
  - `delete(int $issueImageId): void`
  - `accountOfIssue(int $issueId): ?int` (the `user_id` of the issue's project, unscoped)
  - `callerForAiu(int $aiu): ?\Issues\Caller` (from `agent_inbox_user`: `user_id`, `actor_type = 'human'`)
  - `projectName(int $projectId): ?string`
  - `transaction(\Closure $work): mixed`
- `final readonly class ProjectImage { IssueImageRow $image; }` (home issue id is on the row; kept as a class so the list can grow a field).
- `final readonly class ImageFiles { __construct(string $root) }` with `absolute(ImagePath $path): string`, `put(string $sourcePath, ImagePath $path): void` (creates directories 0700, copies to a temporary name in the target directory, chmod 0600, renames; throws `\RuntimeException` if the target exists), `exists(ImagePath): bool`, `delete(ImagePath): void`.
- `ImageSchema::create(\PDO $pdo): void` creates SQLite versions of `projects`, `issues`, `agent_inbox_user`, `issue_images`, `issue_image_links` with the columns these queries touch.

- [ ] **Step 1: Failing tests.** Cover: insert then `find`/`findAtHome`; `link` twice answers true then false; `forIssue` order with two batches (`t1`: `02.png`, `01.png`; `t2`: `09.png`) is `09, 01, 02`; `forProject` returns a picture linked to two issues of the project once, and not a picture linked only to another project's issue, and honours the limit; `unlinked` after the last `unlink`; `delete` removes the row; `accountOfIssue` and `callerForAiu` for a human and an agent; `ImageFiles::put` writes mode 0600 under 0700 directories, refuses an existing target, leaves no temporary file behind, `delete` of a missing file is silent.
- [ ] **Step 2: Fail. Step 3: Implement** with SQL that runs on both MySQL and SQLite (no `ON DUPLICATE KEY`; `link` checks then inserts inside the caller's transaction). Timestamps written with `RowValue::DATETIME_FORMAT`.
- [ ] **Step 4: Pass + gates. Step 5: Commit** (store, then files, as two commits).

### Task 5: The rules

**Files:**
- Create: `classes/Issues/Images/ImageService.php`, `UploadOutcome.php`, `UploadResult.php`, `ImageView.php`
- Test: `Tests/Unit/Issues/ImageServiceTest.php` (extends `IssueCase` for its callers and `FakeIssueStore`; a real `PdoImageStore` on SQLite; `ImageFiles` on a temp dir)

**Interfaces:**
- Consumes: `IssueService::view(Caller, int): IssueView` (`->issue->projectId`, `->membership->canWrite`; throws `NotFoundException` when unreadable), Tasks 2-4.
- Produces:
  - `enum UploadOutcome: string { Stored = 'stored'; AlreadyThere = 'already there'; LinkedAgain = 'linked again'; Refused = 'refused'; }`
  - `final readonly class UploadResult { string $originalName; UploadOutcome $outcome; ?IssueImageRow $image; string $reason; }`
  - `final readonly class ImageView { IssueImageRow $image; string $url; string $markdown; }`
  - `final readonly class ImageService { __construct(IssueService $issues, ImageStore $store, ?ImageFiles $files) }` (`$files` null = feature off)
    - `enabled(): bool`
    - `upload(Caller $caller, int $issueId, array $files, \DateTimeImmutable $now, bool $dryRun = false): list<UploadResult>` where `$files` is `list<array{path: string, name: string}>`
    - `forIssue(Caller $caller, int $issueId): list<ImageView>`
    - `forProject(Caller $caller, int $projectId, int $limit = 100): list<ImageView>`
    - `readable(Caller $caller, ImagePath $path): ?IssueImageRow`
    - `absolutePath(IssueImageRow $row): string`
    - `link(Caller $caller, int $issueImageId, int $issueId, \DateTimeImmutable $now): bool`
    - `unlink(Caller $caller, int $issueImageId, int $issueId): bool`
    - `purge(bool $dryRun): list<IssueImageRow>`

Rules, each with a test:

- `upload`: the caller must be able to write the issue, else `AccessException` (reader) or `NotFoundException` (stranger), and nothing is stored. Feature off → `\LogicException`.
- Per file: `ImageCheck::file`; a refusal becomes a `Refused` result with the reason and the batch carries on.
- Same-name rule against `(issueId, year of $now in UTC)`: a row with the cleaned name and the same sha → `AlreadyThere` if still linked to this issue, `LinkedAgain` (and the link is restored) if not. A row with the same name and different bytes → try `numbered(name, 2)`, `3`, ... ; if a numbered name holds the same sha, that is the `AlreadyThere`/`LinkedAgain` match. Two files in one batch with the same name follow the same rule against each other.
- Every stored file in one call gets `$now` as `uploaded_at_utc`, the caller's aiu, and a first link to the issue.
- Row and link are written in one transaction per file; the file is put on disk before the transaction commits and deleted if the transaction fails, so a row never points at a missing file and a failure leaves no stray file.
- `dryRun` runs every check and the same-name rule and reports the outcomes it would give, with no row, link or file written.
- `readable`: finds the row by `(issueId, year, filename)` from the path, requires `home_project_id === $path->projectId`, then answers the row only if `IssueService::view` succeeds for at least one linked issue; otherwise null. Feature off → null.
- `forIssue` requires read access (`view`), `forProject` requires read access on the project (use `IssueService::board($caller, $projectId)`'s check or the membership it exposes; whichever read check exists without listing issues). Both answer `[]` when the feature is off. URLs use the home project's current name.
- `link`: the caller must be able to read the picture (some linked issue readable) and write the target issue; so a link cannot leave the caller's account. `unlink`: the caller must write that issue.
- `purge`: answers the rows with no links; unless dry, deletes each file then its row.

- [ ] **Step 1: Failing tests** for every rule above plus Review Focus 1, 4 and 5: the mismatched-home URL; reader/stranger/blocked callers for upload, remove, link and `readable`; `readable` turning null after the only readable link is removed; the same batch uploaded twice leaves the same file count on disk and all `AlreadyThere`; a store that throws on insert (a small decorating store in the test) leaves the directory without the file.
- [ ] **Step 2: Fail. Step 3: Implement. Step 4: Pass + gates. Step 5: Commit.**

### Task 6: Serving

**Files:**
- Create: `wwwroot/issues/image.php`
- Modify: `wwwroot/.htaccess`

- [ ] **Step 1:** `.htaccess`, inside the existing `mod_rewrite` block after the HTTPS redirect: `RewriteRule ^images/projects/ /issues/image.php [L]`, with a comment that nothing named `images` exists under `wwwroot/` and why.
- [ ] **Step 2:** `image.php` includes `prepend.php` in the standard way. Any of: not logged in, not admin and not paid, no human caller, `ImagePath::fromUrlPath(parse_url(REQUEST_URI, PHP_URL_PATH))` null, `readable()` null, file missing on disk → `http_response_code(404); exit;` with no body and no redirect. Otherwise send `Content-Type` from the row's `mime_type` (only after `ImageType::fromMime` accepts it), `Content-Length`, `Cache-Control: private, max-age=86400`, `Content-Disposition: inline; filename="<filename>"`, then `readfile`. A direct request for `/issues/image.php` has no matching path and so 404s.
- [ ] **Step 3:** A small factory so pages and tools build the service one way: `Issues\Images\ImageService::forConfig(\Config $config, \PDO $pdo): self` (`$files` null when `image_store_path` is empty or not a directory).
- [ ] **Step 4: Gates. Commit** (`.htaccess` and script as separate commits).

### Task 7: The pages

**Files:**
- Create: `wwwroot/issues/images.php` (POST: add, remove), `templates/issues/image_strip.tpl.php`, `wwwroot/js/issue-images.js`
- Modify: `wwwroot/issues/view.php`, `templates/issues/view.tpl.php`, `wwwroot/projects/view.php`, `templates/projects/view.tpl.php`, the stylesheet that holds `.issue-description`

- [ ] **Step 1: `images.php`.** Same login/paid/POST guards as `wwwroot/issues/comment.php`. `action=add`: reads `$_FILES['images']` (multiple), takes at most 20, skips entries whose `error` is not `UPLOAD_ERR_OK` with a note naming the file and why (`UPLOAD_ERR_INI_SIZE`/`FORM_SIZE` → `larger than the server allows`), passes `tmp_name` + original `name` to `ImageService::upload` with one `$now`. `action=remove`: `issue_image_id` + `issue_id` → `unlink`. Notes for the page (one line per file refused, already there or linked again) go in `$_SESSION['issue_image_notes']` as a list of strings; redirect to `/issues/view.php?issue_id=N#images`. Access and not-found redirects as in `comment.php`.
- [ ] **Step 2: Strip partial.** One template used by both pages, given `images` (`list<ImageView>`), `can_write` (bool), `issue_id` (int or null on the project page), `notes` (list of strings), `show_home` (bool). Markup: a card `id="images"`, heading `Images (N)`, the notes, the form (file input `name="images[]" multiple accept="image/png,image/jpeg,image/gif,image/webp"`, `<?= $csrf->field() ?>`, hidden `action=add` and `issue_id`, button `Add images`) when `can_write` and `issue_id` is set, then `<div class="image-strip">` holding one `<figure>` per picture: `<a href=url target="_blank" rel="noopener"><img src=url alt=filename width= height= loading="lazy"></a>`, `<figcaption>` with the filename, on the project page a link `#<home issue id>` to the issue, a `copy` button carrying the markdown in `data-markdown`, and when writable a `remove` form (POST, CSRF, `action=remove`) whose button asks `Remove this picture from the issue? The file stays on the server.` Everything escaped with `htmlspecialchars`. No card at all when there are no pictures and no form to show.
- [ ] **Step 3: CSS.** `.image-strip { display: flex; gap: 0.75rem; overflow-x: auto; }`, figures do not shrink, images `height: 160px; width: auto;`, caption text truncates to the image's width; colours from the existing card variables so dark mode follows. Also `.issue-description img, .comment-body img { max-width: 100%; height: auto; }` using whatever class the comment body really has.
- [ ] **Step 4: JS.** `issue-images.js`: copy buttons write `data-markdown` to the clipboard and say `copied` for a moment; on submit of the add form, block and name the offender when more than 20 files are chosen or any file is over 10 MB. Loaded only when the strip is on the page.
- [ ] **Step 5: Wire the pages.** `issues/view.php` builds the service, calls `forIssue`, reads and clears the session notes, renders the partial into `image_strip` for the template, which prints it between the description card and Sub-issues. `projects/view.php` does the same with `forProject` (`show_home` true, no form, no remove) and prints it above the issue tables.
- [ ] **Step 6: Gates. Commits:** handler; partial + CSS + JS; issue page wiring; project page wiring.

### Task 8: The Lemur 13 side

**Files:**
- Create: `tools/issue_images.php` (server side, CLI only), `tools/issue-images/upload.sh`, `link.sh`, `unlink.sh`, `purge.sh`
- Modify: `docs/superpowers/specs/2026-11-23-issue-images-design.md` (the importer's filename), `CLAUDE.md`

**Interfaces:**
- `tools/issue_images.php import --issue=<id> --dir=<server dir> [--as=<aiu>] [--dry-run]`, `link --image=<id> --issue=<id> [--as=<aiu>]`, `unlink ...`, `purge [--dry-run]`. Bootstraps like `tools/import_site_standards.php`. The caller is `callerForAiu(--as)` or the human caller of `accountOfIssue(--issue)`; an unknown aiu or issue is `REFUSED:` on stderr, exit 1. `import` takes the regular files directly in `--dir` in name order, prints `<outcome>  <name>  [reason]` per file, then a blank line and the markdown line of every stored or already-present picture; exit 1 if any was refused. `--dir` must be under the system temp directory or the user's home and is never deleted by the PHP side.
- `upload.sh [--dry-run] [--as=<aiu>] <issue_id> <file-or-dir>...`: collects the files (a directory contributes its direct regular files; names that are not `png jpg jpeg gif webp` are listed as skipped), refuses two inputs with the same basename, `ssh mg mktemp -d`, a `trap` that removes that directory on any exit, `scp -q` the files into it, runs the importer, passes its output and exit status through. `issue_id` and `--as` must be all digits before anything is sent.
- `link.sh <issue_image_id> <issue_id> [--as=<aiu>]`, `unlink.sh` likewise, `purge.sh [--dry-run]`: one ssh call each, digits checked first.

- [ ] **Step 1:** Write `tools/issue_images.php`; unit-test nothing new here (the rules are in `ImageService`), but lint it and run PHPStan.
- [ ] **Step 2:** Write the four scripts, `chmod +x`, `bash -n` and `shellcheck` if present. `git ls-files tools/issue-images` must list all four (the `php*` ignore rule).
- [ ] **Step 3:** `CLAUDE.md` section "Images on issues" (where files live, the two ways in, access rule, that only `purge.sh` deletes, the config property), in the style of its neighbours.
- [ ] **Step 4: Gates. Commits:** PHP tool; scripts; docs.
- [ ] **Step 5 (needs Rob):** after Rob applies migration 56 and deploys the branch: regenerate the row classes from the real dump and confirm no diff; read web PHP's `upload_max_filesize` and `post_max_size` (a throwaway check through the deployed site, removed afterwards, or Rob's panel) and adjust the form's limits if lower; `upload.sh --dry-run` then for real on a test issue; load the issue and project pages; request a picture logged out and expect 404.

### Task 9: Close

- [ ] Full gates once more, `check_tests(repo="mg")`.
- [ ] One fresh reviewer over the whole branch against the spec and the Review Focus list; fix what it finds.
- [ ] Close the bubble with the `close-bubble` skill (`--dry-run` first): `DONE images on issues`.
