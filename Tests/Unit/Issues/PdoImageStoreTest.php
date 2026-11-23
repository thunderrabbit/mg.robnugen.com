<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Issues\Images\CheckedImage;
use Issues\Images\ImageType;
use Issues\Images\PdoImageStore;

/**
 * The image tables' reads and writes, against SQLite in memory.
 */
class PdoImageStoreTest extends Unit
{
    private PdoImageStore $store;
    private \DateTimeImmutable $t1;
    private \DateTimeImmutable $t2;

    protected function _before(): void
    {
        $this->store = new PdoImageStore(ImageSchema::create());
        $this->t1 = new \DateTimeImmutable('2026-11-23 01:00:00.000000', new \DateTimeZone('UTC'));
        $this->t2 = new \DateTimeImmutable('2026-11-24 01:00:00.000000', new \DateTimeZone('UTC'));
    }

    private function checked(string $name, string $sha = 'a'): CheckedImage
    {
        return new CheckedImage(
            path: '/nowhere',
            name: $name,
            type: ImageType::Png,
            byteSize: 123,
            width: 3,
            height: 2,
            sha256: str_repeat($sha, 64),
        );
    }

    /** Inserts a picture homed in an issue of project 7 and links it there. */
    private function add(string $name, \DateTimeImmutable $at, int $issueId = 12, string $sha = 'a'): int
    {
        $id = $this->store->insert(7, $issueId, 2026, $this->checked($name, $sha), $name, 35, $at);
        $this->store->link($id, $issueId, 35, $at);
        return $id;
    }

    /**
     * @param list<\Database\Row\IssueImageRow> $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_map(static fn ($row): string => $row->filename, $rows);
    }

    public function testInsertedPictureReadsBackWhole(): void
    {
        $id = $this->store->insert(7, 12, 2026, $this->checked('ignored.png'), 'a.png', 35, $this->t1);

        $row = $this->store->find($id);

        $this->assertNotNull($row);
        $this->assertSame(7, $row->homeProjectId);
        $this->assertSame(12, $row->homeIssueId);
        $this->assertSame(2026, $row->homeYear);
        $this->assertSame('a.png', $row->filename);
        $this->assertSame('image/png', $row->mimeType);
        $this->assertSame(123, $row->byteSize);
        $this->assertSame(3, $row->width);
        $this->assertSame(2, $row->height);
        $this->assertSame(str_repeat('a', 64), $row->sha256);
        $this->assertSame(35, $row->uploadedByAiu);
        $this->assertEquals($this->t1, $row->uploadedAtUtc);
        $this->assertNull($this->store->find($id + 1));
    }

    public function testFindAtHomeMatchesIssueYearAndName(): void
    {
        $id = $this->add('a.png', $this->t1);

        $this->assertSame($id, $this->store->findAtHome(12, 2026, 'a.png')?->issueImageId);
        $this->assertNull($this->store->findAtHome(12, 2027, 'a.png'));
        $this->assertNull($this->store->findAtHome(13, 2026, 'a.png'));
        $this->assertNull($this->store->findAtHome(12, 2026, 'A.png'));
    }

    public function testAtHomeListsOneIssuesYear(): void
    {
        $this->add('b.png', $this->t1, sha: 'b');
        $this->add('a.png', $this->t1, sha: 'a');
        $this->add('c.png', $this->t1, issueId: 13, sha: 'c');
        $this->store->insert(7, 12, 2027, $this->checked('d.png', 'd'), 'd.png', 35, $this->t2);

        $this->assertSame(['a.png', 'b.png'], $this->names($this->store->atHome(12, 2026)));
    }

    public function testLinkingTwiceIsOneLink(): void
    {
        $id = $this->store->insert(7, 12, 2026, $this->checked('a.png'), 'a.png', 35, $this->t1);

        $this->assertTrue($this->store->link($id, 12, 35, $this->t1));
        $this->assertFalse($this->store->link($id, 12, 1, $this->t2));
        $this->assertTrue($this->store->link($id, 20, 1, $this->t2));
        $this->assertSame([12, 20], $this->store->linkedIssueIds($id));
    }

    public function testUnlinkSaysWhetherThereWasALink(): void
    {
        $id = $this->add('a.png', $this->t1);

        $this->assertTrue($this->store->unlink($id, 12));
        $this->assertFalse($this->store->unlink($id, 12));
        $this->assertSame([], $this->store->linkedIssueIds($id));
    }

    public function testIssueStripIsNewestBatchFirstThenByName(): void
    {
        $this->add('02.png', $this->t1, sha: 'b');
        $this->add('01.png', $this->t1, sha: 'c');
        $this->add('09.png', $this->t2, sha: 'd');
        $this->add('00.png', $this->t2, issueId: 13, sha: 'e');

        $this->assertSame(['09.png', '01.png', '02.png'], $this->names($this->store->forIssue(12)));
    }

    public function testIssueStripIncludesPicturesLinkedFromElsewhere(): void
    {
        $id = $this->add('a.png', $this->t1, issueId: 13);
        $this->store->link($id, 12, 1, $this->t2);

        $this->assertSame(['a.png'], $this->names($this->store->forIssue(12)));
    }

    public function testProjectStripShowsEachPictureOnce(): void
    {
        $id = $this->add('a.png', $this->t1, issueId: 12);
        $this->store->link($id, 13, 1, $this->t2);
        $this->add('b.png', $this->t2, issueId: 13, sha: 'b');

        $this->assertSame(['b.png', 'a.png'], $this->names($this->store->forProject(7, 100)));
    }

    public function testProjectStripFollowsLinksNotHomes(): void
    {
        $id = $this->add('a.png', $this->t1, issueId: 12);
        $this->store->link($id, 20, 1, $this->t2);
        $this->store->unlink($id, 12);

        $this->assertSame([], $this->store->forProject(7, 100));
        $this->assertSame(['a.png'], $this->names($this->store->forProject(8, 100)));
    }

    public function testProjectStripStopsAtItsLimit(): void
    {
        $this->add('a.png', $this->t1, sha: 'a');
        $this->add('b.png', $this->t1, sha: 'b');
        $this->add('c.png', $this->t2, sha: 'c');

        $this->assertSame(['c.png', 'a.png'], $this->names($this->store->forProject(7, 2)));
    }

    public function testUnlinkedListsPicturesNobodyShows(): void
    {
        $kept = $this->add('kept.png', $this->t1, sha: 'a');
        $loose = $this->add('loose.png', $this->t1, sha: 'b');
        $this->store->unlink($loose, 12);

        $this->assertSame(['loose.png'], $this->names($this->store->unlinked()));

        $this->store->delete($loose);

        $this->assertNull($this->store->find($loose));
        $this->assertNotNull($this->store->find($kept));
        $this->assertSame([], $this->store->unlinked());
    }

    public function testAccountOfIssueIsItsProjectsOwner(): void
    {
        $this->assertSame(1, $this->store->accountOfIssue(12));
        $this->assertSame(2, $this->store->accountOfIssue(30));
        $this->assertNull($this->store->accountOfIssue(999));
    }

    public function testCallerForAiuKnowsHumansFromAgents(): void
    {
        $human = $this->store->callerForAiu(1);
        $agent = $this->store->callerForAiu(35);

        $this->assertNotNull($human);
        $this->assertTrue($human->isHuman);
        $this->assertSame(1, $human->userId);
        $this->assertNotNull($agent);
        $this->assertFalse($agent->isHuman);
        $this->assertSame(35, $agent->aiu);
        $this->assertNull($this->store->callerForAiu(999));
    }

    public function testProjectName(): void
    {
        $this->assertSame('jikan', $this->store->projectName(7));
        $this->assertNull($this->store->projectName(999));
    }

    public function testAFailedTransactionWritesNothing(): void
    {
        $thrown = null;
        try {
            $this->store->transaction(function (): never {
                $this->add('a.png', $this->t1);
                throw new \RuntimeException('disk full');
            });
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        $this->assertSame('disk full', $thrown);
        $this->assertNull($this->store->findAtHome(12, 2026, 'a.png'));
    }

    public function testATransactionAnswersWhatItsWorkAnswers(): void
    {
        $this->assertSame(5, $this->store->transaction(static fn (): int => 5));
    }
}
