<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Journal\EntryCheck;
use Journal\EntryPath;

/**
 * The checks a journal file must pass before mg saves it and before Lemur 13
 * writes it. Hermetic: strings in, messages out.
 */
class JournalEntryCheckTest extends Unit
{
    private const PATH = '2026/11/05dream-theater-electrical.md';

    private function file(string $frontmatter, string $body = "#### 05:44\n\nWords.\n"): string
    {
        return "---\n{$frontmatter}\n---\n\n{$body}";
    }

    private function good(): string
    {
        return $this->file(implode("\n", [
            'title: "Dream: Theater electrical"',
            'tags: [ "2026", "dream", "men\'s circle" ]',
            'author: Rob Nugen',
            'date: 2026-11-05T05:44:00+11:00',
            'draft: false',
            'gdoc_id: dream-20261105-044627',
        ]));
    }

    public function testCarrieShapedFileSaves(): void
    {
        $this->assertSame([], EntryCheck::problems($this->good(), self::PATH));
    }

    public function testBrowserLineEndingsAreAccepted(): void
    {
        $crlf = str_replace("\n", "\r\n", $this->good());
        $this->assertSame([], EntryCheck::problems($crlf, self::PATH));
        $this->assertStringNotContainsString("\r", EntryCheck::normalize($crlf));
    }

    public function testMissingOpeningLineIsRefused(): void
    {
        $this->assertCount(1, EntryCheck::problems(substr($this->good(), 4), self::PATH));
    }

    public function testMissingClosingLineIsRefused(): void
    {
        $text = "---\ntitle: \"A\"\ntags: [ \"2026\" ]\ndate: 2026-11-05T05:44:00+11:00\n\nBody";
        $this->assertStringContainsString('closing', EntryCheck::problems($text, self::PATH)[0]);
    }

    public function testUnclosedQuoteIsRefused(): void
    {
        $text = str_replace('"Dream: Theater electrical"', '"Dream: Theater electrical', $this->good());
        $this->assertStringContainsString('does not parse', EntryCheck::problems($text, self::PATH)[0]);
    }

    public function testUnclosedListIsRefused(): void
    {
        $text = str_replace('"men\'s circle" ]', '"men\'s circle"', $this->good());
        $this->assertStringContainsString('does not parse', EntryCheck::problems($text, self::PATH)[0]);
    }

    public function testLineWithoutColonIsRefused(): void
    {
        $text = str_replace('draft: false', 'draft false', $this->good());
        $this->assertStringContainsString('does not parse', EntryCheck::problems($text, self::PATH)[0]);
    }

    public function testDuplicateKeyIsRefused(): void
    {
        $text = str_replace('draft: false', "draft: false\ndraft: true", $this->good());
        $this->assertStringContainsString('does not parse', EntryCheck::problems($text, self::PATH)[0]);
    }

    public function testMissingTitleTagsOrDateIsRefused(): void
    {
        $lines = [
            'title: "Dream: Theater electrical"',
            'tags: [ "2026", "dream", "men\'s circle" ]',
            'date: 2026-11-05T05:44:00+11:00',
        ];
        foreach ($lines as $line) {
            $text = str_replace($line . "\n", '', $this->good());
            $this->assertCount(1, EntryCheck::problems($text, self::PATH), $line);
        }
    }

    public function testEmptyTitleIsRefused(): void
    {
        $text = str_replace('"Dream: Theater electrical"', '""', $this->good());
        $this->assertSame(['The frontmatter needs a title.'], EntryCheck::problems($text, self::PATH));
    }

    public function testDateMustMatchFilename(): void
    {
        $text = str_replace('2026-11-05T05:44', '2026-11-04T05:44', $this->good());
        $problems = EntryCheck::problems($text, self::PATH);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('must match', $problems[0]);
    }

    public function testDateMismatchIsRefusedEvenWhenAlreadyWrong(): void
    {
        $text = str_replace('2026-11-05T05:44', '2026-11-04T05:44', $this->good());
        $this->assertCount(1, EntryCheck::problems($text, self::PATH, $text));
    }

    public function testBadDateShapeIsRefused(): void
    {
        $text = str_replace('2026-11-05T05:44:00+11:00', '5 Nov 2026', $this->good());
        $this->assertCount(1, EntryCheck::problems($text, self::PATH));
    }

    public function testDateWithoutTimeIsRefusedWhenNew(): void
    {
        $text = str_replace('2026-11-05T05:44:00+11:00', '2026-11-05', $this->good());
        $this->assertCount(1, EntryCheck::problems($text, self::PATH, $this->good()));
    }

    public function testDateWithoutTimeIsKeptWhenTheFileAlreadyHadIt(): void
    {
        $old = str_replace('2026-11-05T05:44:00+11:00', '2026-11-05', $this->good());
        $edited = str_replace('Words.', 'Better words.', $old);
        $this->assertSame([], EntryCheck::problems($edited, self::PATH, $old));
    }

    public function testMissingTagsAreKeptWhenTheFileAlreadyLackedThem(): void
    {
        $old = str_replace("tags: [ \"2026\", \"dream\", \"men's circle\" ]\n", '', $this->good());
        $this->assertSame([], EntryCheck::problems($old, self::PATH, $old));
        $this->assertCount(1, EntryCheck::problems($old, self::PATH, $this->good()));
    }

    public function testEmptyTagListIsAllowed(): void
    {
        $text = str_replace('[ "2026", "dream", "men\'s circle" ]', '[]', $this->good());
        $this->assertSame([], EntryCheck::problems($text, self::PATH));
    }

    public function testBareTagListItemsParse(): void
    {
        $this->assertSame(
            ['2026', 'dream', 'x'],
            EntryCheck::frontmatter("---\ntags: [2026, dream , \"x\"]\n---\n")['tags'] ?? null,
        );
    }

    public function testTrailingCommaInListIsRefused(): void
    {
        $this->assertNull(EntryCheck::frontmatter("---\ntags: [ \"a\", ]\n---\n"));
    }

    public function testCommentAndBlankLinesAreIgnored(): void
    {
        $text = str_replace('draft: false', "draft: false\n\n# note", $this->good());
        $this->assertSame([], EntryCheck::problems($text, self::PATH));
    }

    public function testDreamWarningWhenRenameDropsTheWord(): void
    {
        $this->assertCount(1, EntryCheck::warnings('2026/11/05theater.md', self::PATH));
        $this->assertSame([], EntryCheck::warnings('2026/11/05dream-theater.md', self::PATH));
        $this->assertSame([], EntryCheck::warnings('2026/11/05x.md', '2026/11/05shaver.md'));
    }

    public function testEntryPathParses(): void
    {
        $p = EntryPath::parse(self::PATH);
        $this->assertNotNull($p);
        $this->assertSame('2026-11-05', $p->date);
        $this->assertSame('dream-theater-electrical', $p->slug);
        $this->assertSame('2026/11/05', $p->day());
        $this->assertTrue($p->isDream());
    }

    public function testEntryPathRefusesNonEntries(): void
    {
        foreach (['2026/02/30x.md', '2026/11/05x.html', '2026/11/05.md', 'x/2026/11/05y.md', '2026/11/5y.md'] as $bad) {
            $this->assertNull(EntryPath::parse($bad), $bad);
        }
    }

    public function testRenameProblemNeedsAPlainSlug(): void
    {
        $this->assertNull(EntryPath::renameProblem('2026/11/04dream-theater_2.md'));
        $this->assertNotNull(EntryPath::renameProblem('2026/11/04dream theater.md'));
        $this->assertNotNull(EntryPath::renameProblem('2026/11/04-dream.md'));
        $this->assertNotNull(EntryPath::renameProblem('2026/11/04../x.md'));
        $this->assertNotNull(EntryPath::renameProblem('2026/13/04x.md'));
    }
}
