<?php

declare(strict_types=1);

namespace Issues\Images;

use Database\Row\IssueImageRow;
use Issues\AccessException;
use Issues\Caller;
use Issues\IssueService;
use Issues\NotFoundException;

/**
 * The picture rules, in one place for the issue pages, the serving script and
 * the command-line tool.
 *
 * A picture belongs to the issues it is linked to. Whoever can read one of
 * those issues can see it; whoever can write an issue can add pictures to it,
 * link an existing picture they can see to it, and remove one from it.
 * IssueService decides reading and writing, so nothing here can reach outside
 * the caller's account.
 *
 * A picture's file is written once, at its home (the issue it was first
 * uploaded to), and only purge() ever deletes one.
 *
 * Without a file store the feature is off: nothing is readable, the strips
 * are empty, and uploading is a programming error.
 */
final readonly class ImageService
{
    public function __construct(
        private IssueService $issues,
        private ImageStore $store,
        private ?ImageFiles $files,
    ) {
    }

    /**
     * The service every page and tool uses, from mg's Config. A Config.php
     * written before this feature has no image_store_path; that means off,
     * like an empty one, so deploying the code first breaks nothing.
     */
    public static function forConfig(object $config, \PDO $pdo): self
    {
        $path = get_object_vars($config)['image_store_path'] ?? '';

        return self::at(is_string($path) ? $path : '', $pdo);
    }

    /**
     * On mg's database, with pictures under the given directory. The feature
     * is on only when that is an existing absolute directory and the image
     * tables have been created.
     */
    public static function at(string $imageStorePath, \PDO $pdo): self
    {
        $store = new PdoImageStore($pdo);
        $usable = str_starts_with($imageStorePath, '/') && is_dir($imageStorePath) && $store->ready();

        return new self(
            new IssueService(new \Issues\PdoIssueStore($pdo)),
            $store,
            $usable ? new ImageFiles($imageStorePath) : null,
        );
    }

    public function enabled(): bool
    {
        return $this->files !== null;
    }

    // ─── Uploading ───────────────────────────────────────────────────────────

    /**
     * Adds pictures to an issue. Every file gets a result, in order; a refused
     * file, or one the disk or database would not take, does not stop the rest. All the pictures stored by one call share
     * $now as their upload time.
     *
     * A name already used in this issue this year: the same bytes are the same
     * picture (nothing new is stored, and it is linked again if it had been
     * removed); different bytes are stored as name-2, name-3, ...
     *
     * @param list<array{path: string, name: string}> $files where each file is now, and
     *        the name it arrived under
     * @return list<UploadResult>
     * @throws NotFoundException when the caller cannot see the issue
     * @throws AccessException when they can read it but not write it
     */
    public function upload(
        Caller $caller,
        int $issueId,
        array $files,
        \DateTimeImmutable $now,
        bool $dryRun = false,
    ): array {
        $disk = $this->files ?? throw new \LogicException('Issue images are switched off');
        $projectId = $this->writableIssue($caller, $issueId);
        $year = (int) $now->setTimezone(new \DateTimeZone('UTC'))->format('Y');

        $results = [];
        // What a dry run would have stored so far: lowercased name => sha256.
        $imagined = [];
        foreach ($files as $file) {
            try {
                $checked = ImageCheck::file($file['path'], $file['name']);
            } catch (ImageRefused $refused) {
                $results[] = new UploadResult($file['name'], UploadOutcome::Refused, reason: $refused->getMessage());
                continue;
            }

            $taken = $imagined;
            $existing = [];
            foreach ($this->store->atHome($issueId, $year) as $row) {
                $taken[strtolower($row->filename)] = $row->sha256;
                $existing[strtolower($row->filename)] = $row;
            }

            $name = $checked->name;
            for ($n = 2;; $n++) {
                $key = strtolower($name);
                $path = new ImagePath($projectId, $year, $issueId, $name);
                if (!isset($taken[$key]) && !$disk->exists($path)) {
                    break;
                }
                if (($taken[$key] ?? null) === $checked->sha256) {
                    break;
                }
                $name = ImageName::numbered($checked->name, $n);
            }

            $same = $existing[$key] ?? null;
            if ($same !== null) {
                $linked = in_array($issueId, $this->store->linkedIssueIds($same->issueImageId), true);
                if (!$linked && !$dryRun) {
                    $this->store->link($same->issueImageId, $issueId, $caller->aiu, $now);
                }
                $results[] = new UploadResult(
                    $file['name'],
                    $linked ? UploadOutcome::AlreadyThere : UploadOutcome::LinkedAgain,
                    $same,
                );
                continue;
            }
            if (isset($taken[$key])) {
                // The same bytes twice in one dry run.
                $results[] = new UploadResult($file['name'], UploadOutcome::AlreadyThere);
                continue;
            }
            if ($dryRun) {
                $imagined[$key] = $checked->sha256;
                $results[] = new UploadResult($file['name'], UploadOutcome::Stored);
                continue;
            }

            try {
                $kept = $this->keep($disk, $checked, $path, $caller, $now);
                $results[] = new UploadResult($file['name'], UploadOutcome::Stored, $kept);
            } catch (\RuntimeException $failure) {
                // The disk or the database said no (PDOException is one too).
                // The person hears that it was not stored; the server's log
                // gets why, which may name paths.
                error_log("Issue image {$path->relative()} could not be stored: {$failure->getMessage()}");
                $results[] = new UploadResult($file['name'], UploadOutcome::Refused, reason: 'could not be stored');
            }
        }
        return $results;
    }

    // ─── Seeing ──────────────────────────────────────────────────────────────

    /**
     * The picture a URL names, if the caller may see it; null for every other
     * case, so the caller of this can answer them all alike.
     */
    public function readable(Caller $caller, ImagePath $path): ?IssueImageRow
    {
        if ($this->files === null) {
            return null;
        }
        $image = $this->store->findAtHome($path->issueId, $path->year, $path->filename);
        if ($image === null || $image->homeProjectId !== $path->projectId) {
            return null;
        }
        return $this->canSee($caller, $image) ? $image : null;
    }

    /**
     * Where a picture's file is. Only for a row readable() or a strip answered.
     */
    public function absolutePath(IssueImageRow $image): string
    {
        $disk = $this->files ?? throw new \LogicException('Issue images are switched off');
        return $disk->absolute(ImagePath::ofRow($image));
    }

    /**
     * One picture with its URL and markdown line. Only for a row an upload,
     * readable() or a strip answered.
     */
    public function view(IssueImageRow $image): ImageView
    {
        return $this->views([$image])[0];
    }

    /**
     * The pictures an issue shows: newest upload first, a batch in name order.
     *
     * @return list<ImageView>
     * @throws NotFoundException when the caller cannot see the issue
     */
    public function forIssue(Caller $caller, int $issueId): array
    {
        if ($this->files === null) {
            return [];
        }
        $this->issues->view($caller, $issueId);
        return $this->views($this->store->forIssue($issueId));
    }

    /**
     * The pictures any issue of a project shows, each once, newest first.
     *
     * @return list<ImageView>
     * @throws NotFoundException|AccessException when the caller may not read the project
     */
    public function forProject(Caller $caller, int $projectId, int $limit = 100): array
    {
        if ($this->files === null) {
            return [];
        }
        $this->issues->requireProjectRead($caller, $projectId);
        return $this->views($this->store->forProject($projectId, $limit));
    }

    // ─── Linking and removing ────────────────────────────────────────────────

    /**
     * Shows a picture the caller can see on another issue they can write.
     * False when it was already there.
     *
     * @throws NotFoundException when the caller cannot see the picture or the issue
     * @throws AccessException when they cannot write the issue
     */
    public function link(Caller $caller, int $issueImageId, int $issueId, \DateTimeImmutable $now): bool
    {
        $image = $this->store->find($issueImageId);
        if ($image === null || !$this->canSee($caller, $image)) {
            throw new NotFoundException("Picture {$issueImageId} not found or no access");
        }
        $this->writableIssue($caller, $issueId);
        return $this->store->link($issueImageId, $issueId, $caller->aiu, $now);
    }

    /**
     * Takes a picture off an issue. The file stays. False when it was not there.
     *
     * @throws NotFoundException when the caller cannot see the issue
     * @throws AccessException when they cannot write it
     */
    public function unlink(Caller $caller, int $issueImageId, int $issueId): bool
    {
        $this->writableIssue($caller, $issueId);
        return $this->store->unlink($issueImageId, $issueId);
    }

    // ─── Purging ─────────────────────────────────────────────────────────────

    /**
     * Deletes the file and row of every picture no issue shows, and answers
     * them. A dry run only answers them. The one place a file is deleted.
     *
     * @return list<IssueImageRow>
     */
    public function purge(bool $dryRun): array
    {
        $disk = $this->files ?? throw new \LogicException('Issue images are switched off');
        $loose = $this->store->unlinked();
        if (!$dryRun) {
            foreach ($loose as $image) {
                $disk->delete(ImagePath::ofRow($image));
                $this->store->delete($image->issueImageId);
            }
        }
        return $loose;
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    /**
     * The project of an issue the caller may write.
     */
    private function writableIssue(Caller $caller, int $issueId): int
    {
        $view = $this->issues->view($caller, $issueId);
        if (!$view->membership->canWrite) {
            throw new AccessException('No write access to this project');
        }
        return $view->issue->projectId;
    }

    private function canSee(Caller $caller, IssueImageRow $image): bool
    {
        foreach ($this->store->linkedIssueIds($image->issueImageId) as $issueId) {
            try {
                $this->issues->view($caller, $issueId);
                return true;
            } catch (NotFoundException) {
                continue;
            }
        }
        return false;
    }

    /**
     * Puts the file at its home, then writes its row and first link together.
     * If the writes fail the file is taken away again, so a row never points
     * at a missing file and a failure leaves no stray file.
     */
    private function keep(
        ImageFiles $disk,
        CheckedImage $checked,
        ImagePath $path,
        Caller $caller,
        \DateTimeImmutable $now,
    ): IssueImageRow {
        $disk->put($checked->path, $path);
        try {
            $id = $this->store->transaction(function () use ($checked, $path, $caller, $now): int {
                $id = $this->store->insert(
                    $path->projectId,
                    $path->issueId,
                    $path->year,
                    $checked,
                    $path->filename,
                    $caller->aiu,
                    $now,
                );
                $this->store->link($id, $path->issueId, $caller->aiu, $now);
                return $id;
            });
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
        return $this->store->find($id) ?? throw new \LogicException("Picture {$id} vanished after insert");
    }

    /**
     * @param list<IssueImageRow> $images
     * @return list<ImageView>
     */
    private function views(array $images): array
    {
        $names = [];
        $views = [];
        foreach ($images as $image) {
            $names[$image->homeProjectId] ??= $this->store->projectName($image->homeProjectId) ?? '';
            $path = ImagePath::ofRow($image);
            $name = $names[$image->homeProjectId];
            $views[] = new ImageView($image, $path->url($name), $path->markdown($name));
        }
        return $views;
    }
}
