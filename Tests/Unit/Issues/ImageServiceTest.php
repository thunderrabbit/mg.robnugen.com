<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Database\Row\IssueRow;
use Issues\AccessException;
use Issues\Caller;
use Issues\Images\ImageFiles;
use Issues\Images\ImagePath;
use Issues\Images\ImageService;
use Issues\Images\PdoImageStore;
use Issues\Images\UploadOutcome;
use Issues\Images\UploadResult;
use Issues\NotFoundException;

/**
 * The picture rules: who may add, see, link and remove one, what happens to a
 * name that is already taken, and that a row and its file never part ways.
 *
 * Issues and memberships come from IssueCase's fake; the image tables are
 * real, in SQLite; the files are real, in a temporary directory.
 */
class ImageServiceTest extends IssueCase
{
    private \PDO $pdo;
    private PdoImageStore $images;
    private ImageService $pictures;
    private string $root;
    private string $incoming;
    private IssueRow $issue;
    private \DateTimeImmutable $now;

    protected function _before(): void
    {
        parent::_before();
        $this->pdo = ImageSchema::create();
        $this->images = new PdoImageStore($this->pdo);
        $this->root = ImageBytes::tempDir();
        $this->incoming = ImageBytes::tempDir();
        $this->pictures = new ImageService($this->service, $this->images, new ImageFiles($this->root));
        $this->issue = $this->newIssue();
        $this->now = new \DateTimeImmutable('2026-11-23 01:30:00', new \DateTimeZone('UTC'));
    }

    /** An issue in the fake and, for the project queries, in SQLite too. */
    private function newIssue(int $projectId = 7): IssueRow
    {
        $issue = $this->create(['project_id' => $projectId]);
        $this->pdo->exec("INSERT INTO issues VALUES ({$issue->issueId}, {$projectId})");
        return $issue;
    }

    /**
     * @return array{path: string, name: string}
     */
    private function file(string $name, string $bytes): array
    {
        $path = ImageBytes::write($this->incoming, bin2hex(random_bytes(4)) . '.tmp', $bytes);
        return ['path' => $path, 'name' => $name];
    }

    /**
     * @param list<array{path: string, name: string}> $files
     * @return list<UploadResult>
     */
    private function upload(array $files, ?Caller $caller = null, ?IssueRow $issue = null): array
    {
        return $this->pictures->upload(
            $caller ?? $this->writer,
            ($issue ?? $this->issue)->issueId,
            $files,
            $this->now,
        );
    }

    /** Uploads one PNG to the test issue and answers its id. */
    private function onePicture(string $name = 'a.png', int $seed = 1): int
    {
        $image = $this->upload([$this->file($name, ImageBytes::png($seed))])[0]->image;
        $this->assertNotNull($image);
        return $image->issueImageId;
    }

    private function pathOf(string $name, ?IssueRow $issue = null): ImagePath
    {
        $issue ??= $this->issue;
        return new ImagePath(projectId: $issue->projectId, year: 2026, issueId: $issue->issueId, filename: $name);
    }

    /**
     * @return list<string>
     */
    private function onDisk(): array
    {
        $dir = "{$this->root}/projects/7/2026/issues/{$this->issue->issueId}";
        return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
    }

    /**
     * @param list<UploadResult> $results
     * @return list<string>
     */
    private function outcomes(array $results): array
    {
        return array_map(static fn (UploadResult $r): string => $r->outcome->value, $results);
    }

    // ─── Uploading ───────────────────────────────────────────────────────────

    public function testAnUploadStoresTheFileItsRowAndItsFirstLink(): void
    {
        $bytes = ImageBytes::png(1);

        $results = $this->upload([$this->file('01-top.png', $bytes)]);

        $this->assertSame(['stored'], $this->outcomes($results));
        $this->assertSame('01-top.png', $results[0]->originalName);
        $image = $results[0]->image;
        $this->assertNotNull($image);
        $this->assertSame(7, $image->homeProjectId);
        $this->assertSame($this->issue->issueId, $image->homeIssueId);
        $this->assertSame(2026, $image->homeYear);
        $this->assertSame('01-top.png', $image->filename);
        $this->assertSame(35, $image->uploadedByAiu);
        $this->assertEquals($this->now, $image->uploadedAtUtc);
        $this->assertSame([$this->issue->issueId], $this->images->linkedIssueIds($image->issueImageId));
        $this->assertSame($bytes, file_get_contents($this->pictures->absolutePath($image)));
    }

    public function testTheYearIsTheUploadYearInUtc(): void
    {
        // 09:00 on New Year's Day in Adelaide is still the old year in UTC.
        $adelaide = new \DateTimeImmutable('2027-01-01 09:00:00', new \DateTimeZone('Australia/Adelaide'));

        $result = $this->pictures->upload(
            $this->writer,
            $this->issue->issueId,
            [$this->file('a.png', ImageBytes::png())],
            $adelaide,
        )[0];

        $this->assertSame(2026, $result->image?->homeYear);
    }

    public function testAReaderMayNotUpload(): void
    {
        $this->assertThrows(
            AccessException::class,
            fn () => $this->upload([$this->file('a.png', ImageBytes::png())], $this->reader),
        );
        $this->assertSame([], $this->onDisk());
    }

    public function testSomeoneWhoCannotSeeTheIssueMayNotUpload(): void
    {
        foreach ([$this->stranger, $this->blocked] as $caller) {
            $this->assertThrows(
                NotFoundException::class,
                fn () => $this->upload([$this->file('a.png', ImageBytes::png())], $caller),
            );
        }
        $this->assertSame([], $this->onDisk());
    }

    public function testARefusedFileDoesNotStopTheBatch(): void
    {
        $results = $this->upload([
            $this->file('page.png', '<html></html>'),
            $this->file('real.png', ImageBytes::png()),
        ]);

        $this->assertSame(['refused', 'stored'], $this->outcomes($results));
        $this->assertSame('not a PNG, JPEG, GIF or WebP picture', $results[0]->reason);
        $this->assertNull($results[0]->image);
        $this->assertSame(['real.png'], $this->onDisk());
    }

    public function testTheSameBatchAgainStoresNothing(): void
    {
        $batch = fn (): array => [
            $this->file('01.png', ImageBytes::png(1)),
            $this->file('02.png', ImageBytes::png(2)),
        ];
        $this->upload($batch());

        $again = $this->upload($batch());

        $this->assertSame(['already there', 'already there'], $this->outcomes($again));
        $this->assertSame(['01.png', '02.png'], $this->onDisk());
        $this->assertCount(2, $this->images->forIssue($this->issue->issueId));
        $this->assertNotNull($again[0]->image);
    }

    public function testARemovedPictureUploadedAgainIsLinkedAgain(): void
    {
        $id = $this->onePicture('a.png', 1);
        $this->pictures->unlink($this->writer, $id, $this->issue->issueId);

        $again = $this->upload([$this->file('a.png', ImageBytes::png(1))]);

        $this->assertSame(['linked again'], $this->outcomes($again));
        $this->assertSame($id, $again[0]->image?->issueImageId);
        $this->assertSame([$this->issue->issueId], $this->images->linkedIssueIds($id));
        $this->assertSame(['a.png'], $this->onDisk());
    }

    public function testADifferentPictureOfTheSameNameIsNumbered(): void
    {
        $this->onePicture('a.png', 1);

        $second = $this->upload([$this->file('a.png', ImageBytes::png(2))]);
        $third = $this->upload([$this->file('a.png', ImageBytes::png(3))]);
        $secondAgain = $this->upload([$this->file('a.png', ImageBytes::png(2))]);

        $this->assertSame('a-2.png', $second[0]->image?->filename);
        $this->assertSame('a-3.png', $third[0]->image?->filename);
        $this->assertSame(['already there'], $this->outcomes($secondAgain));
        $this->assertSame('a-2.png', $secondAgain[0]->image?->filename);
        $this->assertSame(['a-2.png', 'a-3.png', 'a.png'], $this->onDisk());
    }

    public function testTwoFilesOfOneNameInOneBatchAreBothKept(): void
    {
        $results = $this->upload([
            $this->file('a.png', ImageBytes::png(1)),
            $this->file('a.png', ImageBytes::png(2)),
            $this->file('a.png', ImageBytes::png(1)),
        ]);

        $this->assertSame(['stored', 'stored', 'already there'], $this->outcomes($results));
        $this->assertSame(['a-2.png', 'a.png'], $this->onDisk());
    }

    public function testANameThatDiffersOnlyInCaseIsNumbered(): void
    {
        // MySQL's unique key calls A.png and a.png one name.
        $this->onePicture('a.png', 1);

        $result = $this->upload([$this->file('A.png', ImageBytes::png(2))])[0];

        $this->assertSame('A-2.png', $result->image?->filename);
    }

    public function testAStrayFileOnDiskIsNeverWrittenOver(): void
    {
        $dir = "{$this->root}/projects/7/2026/issues/{$this->issue->issueId}";
        mkdir($dir, 0700, true);
        file_put_contents("{$dir}/a.png", 'copied here by hand');

        $result = $this->upload([$this->file('a.png', ImageBytes::png(1))])[0];

        $this->assertSame('a-2.png', $result->image?->filename);
        $this->assertSame('copied here by hand', file_get_contents("{$dir}/a.png"));
    }

    public function testADryRunReportsAndStoresNothing(): void
    {
        $this->onePicture('a.png', 1);

        $results = $this->pictures->upload(
            $this->writer,
            $this->issue->issueId,
            [
                $this->file('a.png', ImageBytes::png(1)),
                $this->file('a.png', ImageBytes::png(2)),
                $this->file('b.png', ImageBytes::png(3)),
                $this->file('b.png', ImageBytes::png(4)),
                $this->file('c.png', 'not a picture'),
            ],
            $this->now,
            dryRun: true,
        );

        $this->assertSame(['already there', 'stored', 'stored', 'stored', 'refused'], $this->outcomes($results));
        $this->assertSame(['a.png'], $this->onDisk());
        $this->assertCount(1, $this->images->forIssue($this->issue->issueId));
    }

    public function testADryRunDoesNotLinkAgain(): void
    {
        $id = $this->onePicture('a.png', 1);
        $this->pictures->unlink($this->writer, $id, $this->issue->issueId);

        $results = $this->pictures->upload(
            $this->writer,
            $this->issue->issueId,
            [$this->file('a.png', ImageBytes::png(1))],
            $this->now,
            dryRun: true,
        );

        $this->assertSame(['linked again'], $this->outcomes($results));
        $this->assertSame([], $this->images->linkedIssueIds($id));
    }

    public function testAFailedWriteIsRefusedAndLeavesNoStrayFile(): void
    {
        $this->pdo->exec('DROP TABLE issue_image_links');

        $results = $this->upload([$this->file('a.png', ImageBytes::png())]);

        $this->assertSame(['refused'], $this->outcomes($results));
        $this->assertSame('could not be stored', $results[0]->reason);
        $this->assertSame([], $this->onDisk());
        $this->assertNull($this->images->findAtHome($this->issue->issueId, 2026, 'a.png'));
    }

    public function testAStoreThatCannotBeWrittenRefusesEachFileAndCarriesOn(): void
    {
        // A plain file where the store's first directory should go: nothing can be made under it.
        file_put_contents($this->root . '/projects', 'in the way');

        $results = $this->upload([
            $this->file('a.png', ImageBytes::png(1)),
            $this->file('b.png', ImageBytes::png(2)),
        ]);

        $this->assertSame(['refused', 'refused'], $this->outcomes($results));
        $this->assertSame('could not be stored', $results[1]->reason);
        $this->assertSame([], $this->images->forIssue($this->issue->issueId));
    }

    public function testUploadingWhileSwitchedOffIsABug(): void
    {
        $off = new ImageService($this->service, $this->images, null);

        $this->assertFalse($off->enabled());
        $this->assertTrue($this->pictures->enabled());
        $this->assertThrows(
            \LogicException::class,
            fn () => $off->upload($this->writer, $this->issue->issueId, [], $this->now),
        );
    }

    public function testTheStorePathSwitchesTheFeatureOnOnlyWhenItIsARealAbsoluteDirectory(): void
    {
        $this->assertTrue(ImageService::at($this->root, $this->pdo)->enabled());
        $this->assertFalse(ImageService::at('', $this->pdo)->enabled());
        $this->assertFalse(ImageService::at($this->root . '/not-made', $this->pdo)->enabled());
        $this->assertFalse(ImageService::at('relative/path', $this->pdo)->enabled());
        $this->assertFalse(ImageService::at('.', $this->pdo)->enabled());
    }

    public function testTheFeatureStaysOffUntilItsTablesExist(): void
    {
        $bare = new \PDO('sqlite::memory:');
        $bare->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->assertFalse(ImageService::at($this->root, $bare)->enabled());
    }

    public function testAConfigWithoutThePropertyMeansOff(): void
    {
        $old = new \stdClass();
        $new = new \stdClass();
        $new->image_store_path = $this->root;
        $odd = new \stdClass();
        $odd->image_store_path = null;

        $this->assertFalse(ImageService::forConfig($old, $this->pdo)->enabled());
        $this->assertFalse(ImageService::forConfig($odd, $this->pdo)->enabled());
        $this->assertTrue(ImageService::forConfig($new, $this->pdo)->enabled());
    }

    // ─── Seeing ──────────────────────────────────────────────────────────────

    public function testAReaderOfTheIssueMaySeeItsPicture(): void
    {
        $id = $this->onePicture();

        foreach ([$this->writer, $this->reader, $this->human] as $caller) {
            $this->assertSame($id, $this->pictures->readable($caller, $this->pathOf('a.png'))?->issueImageId);
        }
    }

    public function testSomeoneWhoCannotReadTheIssueSeesNothing(): void
    {
        $this->onePicture();

        foreach ([$this->stranger, $this->blocked, new Caller(userId: 2, aiu: 2, isHuman: true)] as $caller) {
            $this->assertNull($this->pictures->readable($caller, $this->pathOf('a.png')));
        }
    }

    public function testAUrlMustNameThePicturesRealHome(): void
    {
        $this->onePicture();
        $id = $this->issue->issueId;

        $this->assertNull($this->pictures->readable($this->writer, new ImagePath(8, 2026, $id, 'a.png')));
        $this->assertNull($this->pictures->readable($this->writer, new ImagePath(7, 2025, $id, 'a.png')));
        $this->assertNull($this->pictures->readable($this->writer, new ImagePath(7, 2026, $id + 50, 'a.png')));
        $this->assertNull($this->pictures->readable($this->writer, new ImagePath(7, 2026, $id, 'b.png')));
    }

    public function testAPictureLinkedElsewhereIsNotServedUnderThatIssuesUrl(): void
    {
        $id = $this->onePicture();
        $other = $this->newIssue();
        $this->pictures->link($this->writer, $id, $other->issueId, $this->now);

        $this->assertNull($this->pictures->readable($this->writer, $this->pathOf('a.png', $other)));
        $this->assertNotNull($this->pictures->readable($this->writer, $this->pathOf('a.png')));
    }

    public function testALinkToAReadableIssueIsEnoughToSee(): void
    {
        // Homed in project 8, which the reader is not on; linked to project 7, which they read.
        $elsewhere = $this->newIssue(8);
        $image = $this->upload([$this->file('a.png', ImageBytes::png())], issue: $elsewhere)[0]->image;
        $this->assertNotNull($image);
        $path = $this->pathOf('a.png', $elsewhere);
        $this->assertNull($this->pictures->readable($this->reader, $path));

        $this->pictures->link($this->writer, $image->issueImageId, $this->issue->issueId, $this->now);

        $seen = $this->pictures->readable($this->reader, $this->pathOf('a.png', $elsewhere));
        $this->assertNotNull($seen);
        $this->assertSame($image->issueImageId, $seen->issueImageId);
    }

    public function testAfterItsOnlyReadableLinkGoesNobodySeesIt(): void
    {
        $id = $this->onePicture();

        $this->pictures->unlink($this->writer, $id, $this->issue->issueId);

        $this->assertNull($this->pictures->readable($this->writer, $this->pathOf('a.png')));
        $this->assertNull($this->pictures->readable($this->human, $this->pathOf('a.png')));
    }

    public function testNothingIsReadableWhileSwitchedOff(): void
    {
        $this->onePicture();
        $off = new ImageService($this->service, $this->images, null);

        $this->assertNull($off->readable($this->writer, $this->pathOf('a.png')));
        $this->assertSame([], $off->forIssue($this->writer, $this->issue->issueId));
        $this->assertSame([], $off->forProject($this->writer, 7));
    }

    // ─── The strips ──────────────────────────────────────────────────────────

    public function testTheIssueStripCarriesUrlAndMarkdown(): void
    {
        $this->onePicture('01-top.png');

        $strip = $this->pictures->forIssue($this->reader, $this->issue->issueId);

        $this->assertCount(1, $strip);
        $url = "/images/projects/7-jikan/2026/issues/{$this->issue->issueId}/01-top.png";
        $this->assertSame($url, $strip[0]->url);
        $this->assertSame("![01-top]({$url})", $strip[0]->markdown);
        $this->assertSame('01-top.png', $strip[0]->image->filename);
    }

    public function testTheIssueStripNeedsReadAccess(): void
    {
        $this->onePicture();

        $this->assertThrows(
            NotFoundException::class,
            fn () => $this->pictures->forIssue($this->stranger, $this->issue->issueId),
        );
    }

    public function testTheProjectStripShowsEveryIssuesPictures(): void
    {
        $this->onePicture('a.png', 1);
        $other = $this->newIssue();
        $this->upload([$this->file('b.png', ImageBytes::png(2))], issue: $other);

        $names = array_map(
            static fn ($view): string => $view->image->filename,
            $this->pictures->forProject($this->reader, 7),
        );

        $this->assertSame(['a.png', 'b.png'], $names);
    }

    public function testTheProjectStripNeedsReadAccess(): void
    {
        $this->assertThrows(NotFoundException::class, fn () => $this->pictures->forProject($this->stranger, 7));
        $this->assertThrows(AccessException::class, fn () => $this->pictures->forProject($this->blocked, 7));
    }

    // ─── Linking and removing ────────────────────────────────────────────────

    public function testLinkingShowsThePictureOnAnotherIssue(): void
    {
        $id = $this->onePicture();
        $other = $this->newIssue(8);

        $this->assertTrue($this->pictures->link($this->writer, $id, $other->issueId, $this->now));
        $this->assertFalse($this->pictures->link($this->writer, $id, $other->issueId, $this->now));

        $this->assertCount(1, $this->pictures->forIssue($this->writer, $other->issueId));
    }

    public function testLinkingNeedsWriteAccessToTheTargetIssue(): void
    {
        $id = $this->onePicture();
        $other = $this->newIssue();

        $this->assertThrows(
            AccessException::class,
            fn () => $this->pictures->link($this->reader, $id, $other->issueId, $this->now),
        );
        $this->assertSame([$this->issue->issueId], $this->images->linkedIssueIds($id));
    }

    public function testAPictureYouCannotSeeCannotBeLinked(): void
    {
        // Homed in project 8, where aiu 1 is no member; aiu 1 writes project 7.
        $elsewhere = $this->newIssue(8);
        $image = $this->upload([$this->file('a.png', ImageBytes::png())], issue: $elsewhere)[0]->image;
        $this->assertNotNull($image);

        $this->assertThrows(
            NotFoundException::class,
            fn () => $this->pictures->link($this->human, $image->issueImageId, $this->issue->issueId, $this->now),
        );
        $this->assertThrows(
            NotFoundException::class,
            fn () => $this->pictures->link($this->writer, 9999, $this->issue->issueId, $this->now),
        );
    }

    public function testRemovingNeedsWriteAccess(): void
    {
        $id = $this->onePicture();

        $this->assertThrows(
            AccessException::class,
            fn () => $this->pictures->unlink($this->reader, $id, $this->issue->issueId),
        );
        $this->assertThrows(
            NotFoundException::class,
            fn () => $this->pictures->unlink($this->stranger, $id, $this->issue->issueId),
        );
        $this->assertSame([$this->issue->issueId], $this->images->linkedIssueIds($id));
    }

    public function testRemovingKeepsTheFile(): void
    {
        $id = $this->onePicture();

        $this->assertTrue($this->pictures->unlink($this->writer, $id, $this->issue->issueId));
        $this->assertFalse($this->pictures->unlink($this->writer, $id, $this->issue->issueId));

        $this->assertSame(['a.png'], $this->onDisk());
        $this->assertSame([], $this->pictures->forIssue($this->writer, $this->issue->issueId));
    }

    // ─── Purging ─────────────────────────────────────────────────────────────

    public function testPurgeDeletesOnlyPicturesNobodyShows(): void
    {
        $kept = $this->onePicture('kept.png', 1);
        $loose = $this->onePicture('loose.png', 2);
        $this->pictures->unlink($this->writer, $loose, $this->issue->issueId);

        $dry = $this->pictures->purge(dryRun: true);
        $this->assertSame(['kept.png', 'loose.png'], $this->onDisk());
        $this->assertNotNull($this->images->find($loose));

        $purged = $this->pictures->purge(dryRun: false);

        $this->assertSame(['loose.png'], array_map(static fn ($row): string => $row->filename, $dry));
        $this->assertEquals($dry, $purged);
        $this->assertSame(['kept.png'], $this->onDisk());
        $this->assertNull($this->images->find($loose));
        $this->assertNotNull($this->images->find($kept));
    }
}
