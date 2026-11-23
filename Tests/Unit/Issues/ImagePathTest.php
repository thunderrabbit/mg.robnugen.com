<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Database\Row\IssueImageRow;
use Issues\Images\ImagePath;

/**
 * Where a picture lives, on disk and in a URL, and which URLs are even
 * shaped like a picture's.
 */
class ImagePathTest extends Unit
{
    public function testAUrlIsReadIntoItsFourParts(): void
    {
        $path = ImagePath::fromUrlPath('/images/projects/7-jikan/2026/issues/12/01-top.png');

        $this->assertNotNull($path);
        $this->assertSame(7, $path->projectId);
        $this->assertSame(2026, $path->year);
        $this->assertSame(12, $path->issueId);
        $this->assertSame('01-top.png', $path->filename);
    }

    public function testTheProjectNameIsOptionalAndIgnored(): void
    {
        $bare = ImagePath::fromUrlPath('/images/projects/7/2026/issues/12/a.png');
        $old = ImagePath::fromUrlPath('/images/projects/7-old.name_here/2026/issues/12/a.png');
        $empty = ImagePath::fromUrlPath('/images/projects/7-/2026/issues/12/a.png');

        $this->assertNotNull($bare);
        $this->assertEquals($bare, $old);
        $this->assertEquals($bare, $empty);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notPictureUrls(): array
    {
        return [
            'name without a dash' => ['/images/projects/7x/2026/issues/12/a.png'],
            'no project id' => ['/images/projects/-jikan/2026/issues/12/a.png'],
            'project zero' => ['/images/projects/0/2026/issues/12/a.png'],
            'leading zero' => ['/images/projects/07/2026/issues/12/a.png'],
            'short year' => ['/images/projects/7/26/issues/12/a.png'],
            'no filename' => ['/images/projects/7/2026/issues/12/'],
            'deeper path' => ['/images/projects/7/2026/issues/12/a/b.png'],
            'dot dot' => ['/images/projects/7/2026/issues/12/..'],
            'hidden file' => ['/images/projects/7/2026/issues/12/.a.png'],
            'encoded slash' => ['/images/projects/7/2026/issues/12/a%2Fb.png'],
            'trailing segment' => ['/images/projects/7/2026/issues/12/a.png/extra'],
            'not issues' => ['/images/projects/7/2026/comments/12/a.png'],
            'other root' => ['/other/projects/7/2026/issues/12/a.png'],
            'newline after' => ["/images/projects/7/2026/issues/12/a.png\n"],
            'too long' => ['/images/projects/7/2026/issues/12/' . str_repeat('a', 101)],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider notPictureUrls
     */
    public function testAnythingElseIsNotAPicturePath(string $url): void
    {
        $this->assertNull(ImagePath::fromUrlPath($url));
    }

    public function testRelativeIsThePathUnderTheStore(): void
    {
        $path = new ImagePath(projectId: 7, year: 2026, issueId: 12, filename: 'a.png');

        $this->assertSame('projects/7/2026/issues/12/a.png', $path->relative());
    }

    public function testSlugKeepsWhatAUrlCanCarry(): void
    {
        $this->assertSame('jikan', ImagePath::slug('jikan'));
        $this->assertSame('bfr-barefoot_rob_master', ImagePath::slug('bfr ~/barefoot_rob_master'));
        $this->assertSame('lab.example.com-wp', ImagePath::slug('lab.example.com/wp/'));
        $this->assertSame('', ImagePath::slug('日本語'));
    }

    public function testUrlCarriesTheProjectNameWhenItHasOne(): void
    {
        $path = new ImagePath(projectId: 7, year: 2026, issueId: 12, filename: 'a.png');

        $this->assertSame('/images/projects/7-jikan/2026/issues/12/a.png', $path->url('jikan'));
        $this->assertSame('/images/projects/7/2026/issues/12/a.png', $path->url('日本語'));
    }

    public function testEveryUrlItWritesReadsBack(): void
    {
        $path = new ImagePath(projectId: 7, year: 2026, issueId: 12, filename: 'A_b-1.x.webp');

        $this->assertEquals($path, ImagePath::fromUrlPath($path->url('bfr ~/barefoot_rob_master')));
    }

    public function testMarkdownUsesTheNameWithoutExtensionAsAltText(): void
    {
        $path = new ImagePath(projectId: 7, year: 2026, issueId: 12, filename: '01-top.png');

        $this->assertSame(
            '![01-top](/images/projects/7-jikan/2026/issues/12/01-top.png)',
            $path->markdown('jikan'),
        );
    }

    public function testOfRowIsThePicturesHome(): void
    {
        $row = new IssueImageRow(
            issueImageId: 3,
            homeProjectId: 7,
            homeIssueId: 12,
            homeYear: 2026,
            filename: 'a.png',
            mimeType: 'image/png',
            byteSize: 10,
            width: 3,
            height: 2,
            sha256: str_repeat('a', 64),
            uploadedByAiu: 1,
            uploadedAtUtc: new \DateTimeImmutable('2026-11-23 01:00:00'),
        );

        $this->assertEquals(
            new ImagePath(projectId: 7, year: 2026, issueId: 12, filename: 'a.png'),
            ImagePath::ofRow($row),
        );
    }
}
