<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\SiteQuery;

/**
 * Hermetic: which site an API caller means, read from the query string.
 * Sites are made up.
 */
final class StandardsSiteQueryTest extends Unit
{
    private const string PATH_FORM = 'checkout_path is relative to ~/work, like rob/example.com';

    public function testACheckoutPathIsTaken(): void
    {
        $query = SiteQuery::parse(['checkout_path' => 'rob/example.test']);

        $this->assertSame('rob/example.test', $query->checkoutPath);
        $this->assertNull($query->shortcode);
    }

    public function testAShortcodeIsTaken(): void
    {
        $query = SiteQuery::parse(['shortcode' => 'example']);

        $this->assertSame('example', $query->shortcode);
        $this->assertNull($query->checkoutPath);
    }

    public function testBothAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Give checkout_path or shortcode, not both');
        SiteQuery::parse(['checkout_path' => 'rob/example.test', 'shortcode' => 'example']);
    }

    public function testNeitherIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Give checkout_path or shortcode');
        SiteQuery::parse([]);
    }

    public function testAnEmptyValueCountsAsAbsent(): void
    {
        $query = SiteQuery::parse(['checkout_path' => '', 'shortcode' => 'example']);

        $this->assertSame('example', $query->shortcode);
    }

    public function testAnAbsolutePathIsRefusedWithTheExpectedForm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::PATH_FORM);
        SiteQuery::parse(['checkout_path' => '/home/u/work/rob/example.test']);
    }

    public function testATrailingSlashIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::PATH_FORM);
        SiteQuery::parse(['checkout_path' => 'rob/example.test/']);
    }

    public function testADotDotPathIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::PATH_FORM);
        SiteQuery::parse(['checkout_path' => 'rob/../etc']);
    }

    public function testABadShortcodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a shortcode');
        SiteQuery::parse(['shortcode' => 'Not OK']);
    }

    public function testAnArrayValueIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a shortcode');
        SiteQuery::parse(['shortcode' => ['x']]);
    }
}
