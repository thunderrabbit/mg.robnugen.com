<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\Names;

/**
 * Hermetic: what a step name, a site shortcode and a site-note issue title
 * may look like on the standards page.
 */
final class StandardsNamesTest extends Unit
{
    public function testStepNames(): void
    {
        $this->assertTrue(Names::isStepName('rector_php_8_5_rules'));
        $this->assertFalse(Names::isStepName('_track'));
        $this->assertFalse(Names::isStepName('Has-Hyphen'));
        $this->assertFalse(Names::isStepName(''));
    }

    public function testShortcodes(): void
    {
        $this->assertTrue(Names::isShortcode('mg'));
        $this->assertTrue(Names::isShortcode('example-games'));
        $this->assertFalse(Names::isShortcode('example--games'));
        $this->assertFalse(Names::isShortcode('-example'));
        $this->assertFalse(Names::isShortcode('example_games'));
        $this->assertFalse(Names::isShortcode(str_repeat('a', 25)));
    }

    public function testBranches(): void
    {
        $this->assertTrue(Names::isBranch('master'));
        $this->assertTrue(Names::isBranch('green.branch.master.right.here.dawg'));
        $this->assertTrue(Names::isBranch('feature/jquery-3'));
        $this->assertFalse(Names::isBranch(''));
        $this->assertFalse(Names::isBranch('master..green'));
        $this->assertFalse(Names::isBranch('master; rm -rf'));
        $this->assertFalse(Names::isBranch(str_repeat('a', 101)));
    }

    public function testNoteTitleRoundTrips(): void
    {
        $title = Names::noteTitle('title_of_upgrade', 'example-games');

        $this->assertSame('title_of_upgrade.example-games', $title);
        $this->assertSame(
            ['step' => 'title_of_upgrade', 'shortcode' => 'example-games'],
            Names::parseNoteTitle($title),
        );
    }

    public function testOrdinaryIssueTitlesAreNotNotes(): void
    {
        $this->assertNull(Names::parseNoteTitle('Fix the login page'));
        $this->assertNull(Names::parseNoteTitle('v1.2 release'));
        $this->assertNull(Names::parseNoteTitle('a.b.c'));
        $this->assertNull(Names::parseNoteTitle('title_of_upgrade.example-games '));
    }

    public function testCheckoutPathsStayInsideTheWorkDirectory(): void
    {
        $this->assertTrue(Names::isCheckoutPath('rob/example.test'));
        $this->assertTrue(Names::isCheckoutPath('rob/jobhunt/example.test'));
        $this->assertFalse(Names::isCheckoutPath('/home/someone/work/rob/example.test'));
        $this->assertFalse(Names::isCheckoutPath('rob/../../etc'));
        $this->assertFalse(Names::isCheckoutPath('rob/./example.test'));
        $this->assertFalse(Names::isCheckoutPath('rob//example.test'));
        $this->assertFalse(Names::isCheckoutPath('rob/example.test/'));
        $this->assertFalse(Names::isCheckoutPath('rob/has space'));
        $this->assertFalse(Names::isCheckoutPath('~/work/rob/example.test'));
        $this->assertFalse(Names::isCheckoutPath(''));
    }
}
