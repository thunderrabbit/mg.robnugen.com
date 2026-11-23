<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Issues\Images\PostedFiles;
use Issues\Images\UploadOutcome;
use Issues\Images\UploadResult;

/**
 * Reading $_FILES['images'], which arrives in whatever shape the browser and
 * PHP made of it, and wording what the page says afterwards.
 */
class PostedFilesTest extends Unit
{
    /**
     * @param list<string> $names
     * @param list<int> $errors
     * @return array<string, mixed>
     */
    private function entry(array $names, array $errors): array
    {
        return [
            'name' => $names,
            'tmp_name' => array_map(static fn (string $n): string => "/tmp/php-{$n}", $names),
            'error' => $errors,
        ];
    }

    private function read(mixed $entry, int $max = 20): PostedFiles
    {
        return PostedFiles::read($entry, $max, static fn (string $path): bool => true);
    }

    public function testEachGoodFileBecomesAPathAndAName(): void
    {
        $posted = $this->read($this->entry(['a.png', 'b.png'], [UPLOAD_ERR_OK, UPLOAD_ERR_OK]));

        $this->assertSame(
            [['path' => '/tmp/php-a.png', 'name' => 'a.png'], ['path' => '/tmp/php-b.png', 'name' => 'b.png']],
            $posted->files,
        );
        $this->assertSame([], $posted->notes);
    }

    public function testASingleFileInputIsReadToo(): void
    {
        $posted = $this->read(['name' => 'a.png', 'tmp_name' => '/tmp/php-a.png', 'error' => UPLOAD_ERR_OK]);

        $this->assertSame([['path' => '/tmp/php-a.png', 'name' => 'a.png']], $posted->files);
    }

    public function testAnEmptyFileInputIsNothingAtAll(): void
    {
        $posted = $this->read($this->entry([''], [UPLOAD_ERR_NO_FILE]));

        $this->assertSame([], $posted->files);
        $this->assertSame([], $posted->notes);
    }

    public function testFilesTheServerTurnedAwayAreNamed(): void
    {
        $posted = $this->read($this->entry(
            ['huge.png', 'cut.png', 'ok.png'],
            [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_OK],
        ));

        $this->assertSame([['path' => '/tmp/php-ok.png', 'name' => 'ok.png']], $posted->files);
        $this->assertSame(
            ['huge.png: larger than the server allows', 'cut.png: did not arrive whole'],
            $posted->notes,
        );
    }

    public function testFilesPastTheLimitAreNamedAndLeftOut(): void
    {
        $posted = $this->read($this->entry(['a.png', 'b.png', 'c.png'], [0, 0, 0]), 2);

        $this->assertCount(2, $posted->files);
        $this->assertSame(['c.png: not added, at most 2 pictures at a time'], $posted->notes);
    }

    public function testAPathPhpDidNotUploadIsIgnored(): void
    {
        $posted = PostedFiles::read(
            ['name' => ['a.png'], 'tmp_name' => ['/etc/passwd'], 'error' => [UPLOAD_ERR_OK]],
            20,
            static fn (string $path): bool => false,
        );

        $this->assertSame([], $posted->files);
        $this->assertSame(['a.png: did not arrive whole'], $posted->notes);
    }

    public function testNonsenseIsNothing(): void
    {
        foreach ([null, 'x', [], ['name' => ['a.png']], ['name' => [['deep']], 'tmp_name' => [[]], 'error' => [[]]]] as $bad) {
            $posted = $this->read($bad);
            $this->assertSame([], $posted->files);
        }
    }

    public function testOnlyResultsWorthMentioningBecomeNotes(): void
    {
        $notes = PostedFiles::notes([
            new UploadResult('a.png', UploadOutcome::Stored),
            new UploadResult('b.png', UploadOutcome::AlreadyThere),
            new UploadResult('c.png', UploadOutcome::LinkedAgain),
            new UploadResult('d.png', UploadOutcome::Refused, reason: 'larger than 10 MB'),
        ]);

        $this->assertSame(
            ['b.png: already there', 'c.png: linked again', 'd.png: refused, larger than 10 MB'],
            $notes,
        );
    }

    public function testNotesAreTakenFromTheSessionOnce(): void
    {
        $session = [PostedFiles::SESSION_KEY => ['a.png: already there', 7, 'b.png: linked again'], 'other' => 1];

        $this->assertSame(['a.png: already there', 'b.png: linked again'], PostedFiles::takeNotes($session));
        $this->assertSame(['other' => 1], $session);
        $this->assertSame([], PostedFiles::takeNotes($session));
    }

    public function testNotesThatAreNotAListAreDropped(): void
    {
        $session = [PostedFiles::SESSION_KEY => 'nonsense'];

        $this->assertSame([], PostedFiles::takeNotes($session));
        $this->assertSame([], $session);
    }
}
