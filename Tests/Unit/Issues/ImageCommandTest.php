<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Database\Row\IssueRow;
use Issues\Images\ImageCommand;
use Issues\Images\ImageFiles;
use Issues\Images\ImageService;
use Issues\Images\PdoImageStore;

/**
 * The command line's way in (tools/issue_images.php): who it acts as, what it
 * prints, and when it exits 1.
 */
class ImageCommandTest extends IssueCase
{
    private \PDO $pdo;
    private PdoImageStore $images;
    private ImageCommand $command;
    private string $root;
    private string $dir;
    private IssueRow $issue;

    protected function _before(): void
    {
        parent::_before();
        $this->pdo = ImageSchema::create();
        $this->images = new PdoImageStore($this->pdo);
        $this->root = ImageBytes::tempDir();
        $this->dir = ImageBytes::tempDir();
        $this->command = new ImageCommand(
            new ImageService($this->service, $this->images, new ImageFiles($this->root)),
            $this->images,
            $this->store,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-23 01:30:00', new \DateTimeZone('UTC')),
        );
        $this->issue = $this->newIssue();
    }

    private function newIssue(int $projectId = 7): IssueRow
    {
        $issue = $this->create(['project_id' => $projectId]);
        $this->pdo->exec("INSERT INTO issues VALUES ({$issue->issueId}, {$projectId})");
        return $issue;
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string} exit status, stdout, stderr
     */
    private function cmd(array $args): array
    {
        $result = $this->command->run($args);
        return [$result->exit, implode("\n", $result->out), implode("\n", $result->err)];
    }

    /**
     * @param list<string> $extra
     * @return array{int, string, string}
     */
    private function import(array $extra = []): array
    {
        return $this->cmd(['import', "--issue={$this->issue->issueId}", "--dir={$this->dir}", ...$extra]);
    }

    public function testImportStoresTheDirectorysPicturesInNameOrder(): void
    {
        ImageBytes::write($this->dir, '02-b.png', ImageBytes::png(2));
        ImageBytes::write($this->dir, '01-a.png', ImageBytes::png(1));
        mkdir($this->dir . '/sub');
        ImageBytes::write($this->dir . '/sub', 'deep.png', ImageBytes::png(3));

        [$exit, $out, $err] = $this->import();

        $id = $this->issue->issueId;
        $this->assertSame(0, $exit);
        $this->assertSame('', $err);
        $this->assertSame(
            "stored         01-a.png\n"
            . "stored         02-b.png\n"
            . "\n"
            . "![01-a](/images/projects/7-jikan/2026/issues/{$id}/01-a.png)\n"
            . "![02-b](/images/projects/7-jikan/2026/issues/{$id}/02-b.png)",
            $out,
        );
        $this->assertCount(2, $this->images->forIssue($id));
    }

    public function testWithoutAsTheUploaderIsTheAccountsHuman(): void
    {
        ImageBytes::write($this->dir, 'a.png', ImageBytes::png());

        $this->import();

        $this->assertSame(1, $this->images->forIssue($this->issue->issueId)[0]->uploadedByAiu);
    }

    public function testAsNamesTheUploader(): void
    {
        ImageBytes::write($this->dir, 'a.png', ImageBytes::png());

        [$exit] = $this->import(['--as=35']);

        $this->assertSame(0, $exit);
        $this->assertSame(35, $this->images->forIssue($this->issue->issueId)[0]->uploadedByAiu);
    }

    public function testAsStillNeedsWriteAccess(): void
    {
        ImageBytes::write($this->dir, 'a.png', ImageBytes::png());

        [$exit, $out, $err] = $this->import(['--as=36']);

        $this->assertSame(1, $exit);
        $this->assertSame('', $out);
        $this->assertSame('REFUSED: No write access to this project', $err);
        $this->assertSame([], $this->images->forIssue($this->issue->issueId));
    }

    public function testUnknownActorsAndIssuesAreRefused(): void
    {
        $this->assertSame([1, '', 'REFUSED: no actor 999'], $this->import(['--as=999']));
        $this->assertSame(
            [1, '', 'REFUSED: no issue 9999'],
            $this->cmd(['import', '--issue=9999', "--dir={$this->dir}"]),
        );
        $this->assertSame(
            [1, '', "REFUSED: {$this->dir}/missing is not a directory"],
            $this->cmd(['import', "--issue={$this->issue->issueId}", "--dir={$this->dir}/missing"]),
        );
    }

    public function testARefusedFileIsNamedAndExitsOne(): void
    {
        ImageBytes::write($this->dir, 'a.png', ImageBytes::png());
        ImageBytes::write($this->dir, 'notes.png', 'just text');
        ImageBytes::write($this->dir, 'shot.png', ImageBytes::jpeg());

        [$exit, $out] = $this->import();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("stored         a.png\n", $out);
        $this->assertStringContainsString(
            "refused        notes.png  (not a PNG, JPEG, GIF or WebP picture)\n",
            $out,
        );
        $this->assertStringContainsString("stored         shot.png -> shot.jpg\n", $out);
    }

    public function testADryRunSaysSoAndStoresNothing(): void
    {
        ImageBytes::write($this->dir, 'a.png', ImageBytes::png());

        [$exit, $out] = $this->import(['--dry-run']);

        $this->assertSame(0, $exit);
        $this->assertSame("DRY RUN (nothing stored)\nstored         a.png", $out);
        $this->assertSame([], $this->images->forIssue($this->issue->issueId));
    }

    public function testASecondImportReportsAlreadyThere(): void
    {
        ImageBytes::write($this->dir, 'a.png', ImageBytes::png());
        $this->import();

        [$exit, $out] = $this->import();

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith("already there  a.png\n", $out);
    }

    public function testLinkAndUnlink(): void
    {
        ImageBytes::write($this->dir, 'a.png', ImageBytes::png());
        $this->import();
        $image = $this->images->forIssue($this->issue->issueId)[0]->issueImageId;
        $other = $this->newIssue()->issueId;

        $this->assertSame(
            [0, "linked picture {$image} to issue {$other}", ''],
            $this->cmd(['link', "--image={$image}", "--issue={$other}"]),
        );
        $this->assertSame(
            [0, "picture {$image} was already on issue {$other}", ''],
            $this->cmd(['link', "--image={$image}", "--issue={$other}"]),
        );
        $this->assertSame(
            [0, "took picture {$image} off issue {$other}", ''],
            $this->cmd(['unlink', "--image={$image}", "--issue={$other}"]),
        );
        $this->assertSame(
            [0, "picture {$image} was not on issue {$other}", ''],
            $this->cmd(['unlink', "--image={$image}", "--issue={$other}"]),
        );
        $this->assertSame(
            [1, '', 'REFUSED: Picture 9999 not found or no access'],
            $this->cmd(['link', '--image=9999', "--issue={$other}"]),
        );
    }

    public function testPurgeListsWhatItDeletes(): void
    {
        ImageBytes::write($this->dir, 'a.png', ImageBytes::png());
        $this->import();
        $id = $this->issue->issueId;
        $image = $this->images->forIssue($id)[0]->issueImageId;
        $this->cmd(['unlink', "--image={$image}", "--issue={$id}"]);

        $this->assertSame(
            [0, "DRY RUN (nothing deleted)\nwould purge  projects/7/2026/issues/{$id}/a.png", ''],
            $this->cmd(['purge', '--dry-run']),
        );
        $this->assertSame([0, "purged  projects/7/2026/issues/{$id}/a.png", ''], $this->cmd(['purge']));
        $this->assertSame([0, 'nothing to purge', ''], $this->cmd(['purge']));
        $this->assertFileDoesNotExist("{$this->root}/projects/7/2026/issues/{$id}/a.png");
    }

    public function testBadArgumentsShowTheUsage(): void
    {
        foreach ([[], ['frobnicate'], ['import'], ['import', '--issue=abc', '--dir=/tmp'], ['link', '--image=1']] as $args) {
            [$exit, $out, $err] = $this->cmd($args);
            $this->assertSame(2, $exit);
            $this->assertSame('', $out);
            $this->assertStringStartsWith('usage:', $err);
        }
    }

    public function testSwitchedOffIsRefused(): void
    {
        $off = new ImageCommand(
            new ImageService($this->service, $this->images, null),
            $this->images,
            $this->store,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
        );

        $result = $off->run(['purge']);

        $this->assertSame(1, $result->exit);
        $this->assertSame(['REFUSED: issue images are off: image_store_path is not set to a directory'], $result->err);
    }
}
