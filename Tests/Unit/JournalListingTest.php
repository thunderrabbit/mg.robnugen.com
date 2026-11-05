<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Journal\Listing;

class JournalListingTest extends Unit
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function row(array $over = []): array
    {
        return array_merge([
            'path' => '2026/11/05dream-theater.md',
            'base_content' => "---\ntitle: \"Dream: Theater\"\ntags: []\n---\nbody",
            'edit_content' => null,
            'edit_path' => null,
            'git_state' => 'untracked',
            'status' => 'synced',
            'status_note' => null,
        ], $over);
    }

    public function testTextPrefersTheWaitingEdit(): void
    {
        $this->assertStringContainsString('body', Listing::text($this->row()));
        $this->assertSame('mine', Listing::text($this->row(['edit_content' => 'mine'])));
    }

    public function testShownPathFollowsAWaitingRename(): void
    {
        $this->assertSame('2026/11/05dream-theater.md', Listing::shownPath($this->row()));
        $this->assertSame(
            '2026/11/04dream-stage.md',
            Listing::shownPath($this->row(['edit_content' => 'x', 'edit_path' => '2026/11/04dream-stage.md'])),
        );
    }

    public function testTitleFallsBackToTheFilename(): void
    {
        $this->assertSame('Dream: Theater', Listing::title(Listing::text($this->row()), '2026/11/05x.md'));
        $this->assertSame('05x', Listing::title('no frontmatter', '2026/11/05x.md'));
    }

    public function testLabels(): void
    {
        $this->assertSame('Not committed', Listing::label($this->row())['text']);
        $this->assertSame('Not committed', Listing::label($this->row(['git_state' => 'modified']))['text']);
        $this->assertSame(
            'Committed · leaves 19 Nov',
            Listing::label($this->row(['git_state' => 'committed']))['text'],
        );
        $this->assertSame('edit', Listing::label($this->row(['status' => 'edit_waiting']))['kind']);
        $this->assertSame(
            "Couldn't be applied: bad date",
            Listing::label($this->row(['status' => 'refused', 'status_note' => 'bad date']))['text'],
        );
        $this->assertSame('warn', Listing::label($this->row(['status' => 'conflict']))['kind']);
    }

    public function testOnlyUntrackedFilesRename(): void
    {
        $this->assertTrue(Listing::canRename($this->row()));
        $this->assertFalse(Listing::canRename($this->row(['git_state' => 'modified'])));
        $this->assertFalse(Listing::canRename($this->row(['git_state' => 'committed'])));
    }

    public function testDayHeading(): void
    {
        $this->assertSame('Thu 5 Nov 2026', Listing::dayHeading('2026/11/05x.md'));
    }
}
