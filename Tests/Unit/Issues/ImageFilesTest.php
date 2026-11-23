<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Issues\Images\ImageFiles;
use Issues\Images\ImagePath;

/**
 * The image store's directory: private files, never overwritten.
 */
class ImageFilesTest extends Unit
{
    private string $root;
    private string $incoming;
    private ImageFiles $files;
    private ImagePath $path;

    protected function _before(): void
    {
        $this->root = ImageBytes::tempDir();
        $this->incoming = ImageBytes::write(ImageBytes::tempDir(), 'upload.tmp', 'picture bytes');
        $this->files = new ImageFiles($this->root);
        $this->path = new ImagePath(projectId: 7, year: 2026, issueId: 12, filename: 'a.png');
    }

    public function testPutCopiesTheFileToItsHome(): void
    {
        $this->files->put($this->incoming, $this->path);

        $target = $this->root . '/projects/7/2026/issues/12/a.png';
        $this->assertSame($target, $this->files->absolute($this->path));
        $this->assertSame('picture bytes', file_get_contents($target));
        $this->assertTrue($this->files->exists($this->path));
        $this->assertFileExists($this->incoming);
    }

    public function testFilesAndDirectoriesArePrivate(): void
    {
        $this->files->put($this->incoming, $this->path);

        $this->assertSame(0600, fileperms($this->files->absolute($this->path)) & 0777);
        foreach (['/projects', '/projects/7', '/projects/7/2026', '/projects/7/2026/issues/12'] as $dir) {
            $this->assertSame(0700, fileperms($this->root . $dir) & 0777, $dir);
        }
    }

    public function testPutNeverOverwrites(): void
    {
        $this->files->put($this->incoming, $this->path);
        $other = ImageBytes::write(ImageBytes::tempDir(), 'upload.tmp', 'other bytes');

        try {
            $this->files->put($other, $this->path);
            $this->fail('a second put to the same place should throw');
        } catch (\RuntimeException) {
            $this->assertSame('picture bytes', file_get_contents($this->files->absolute($this->path)));
        }
    }

    public function testNothingTemporaryIsLeftBehind(): void
    {
        $this->files->put($this->incoming, $this->path);

        $this->assertSame(['.', '..', 'a.png'], scandir($this->root . '/projects/7/2026/issues/12'));
    }

    public function testAMissingSourceThrowsAndLeavesNoFile(): void
    {
        try {
            $this->files->put($this->root . '/nothing-here', $this->path);
            $this->fail('a missing source should throw');
        } catch (\RuntimeException) {
            $this->assertFalse($this->files->exists($this->path));
            $this->assertSame(['.', '..'], scandir($this->root . '/projects/7/2026/issues/12'));
        }
    }

    public function testDeleteRemovesTheFileAndIsQuietWhenItIsGone(): void
    {
        $this->files->put($this->incoming, $this->path);

        $this->files->delete($this->path);
        $this->files->delete($this->path);

        $this->assertFalse($this->files->exists($this->path));
    }
}
