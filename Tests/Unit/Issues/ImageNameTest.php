<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Issues\Images\ImageName;
use Issues\Images\ImageType;

/**
 * The stored name of an uploaded picture: always one harmless path segment.
 */
class ImageNameTest extends Unit
{
    public function testOrdinaryNamesAreKeptWithTheirCase(): void
    {
        $this->assertSame('01-weather-top.png', ImageName::clean('01-weather-top.png', ImageType::Png));
        $this->assertSame(
            'Screenshot_20261123-101500.png',
            ImageName::clean('Screenshot_20261123-101500.png', ImageType::Png),
        );
    }

    public function testOtherCharactersBecomeOneDash(): void
    {
        $this->assertSame('my-shot-1.png', ImageName::clean('my shot (1).png', ImageType::Png));
    }

    public function testPathPiecesAreDropped(): void
    {
        $this->assertSame('x.png', ImageName::clean('../../x.png', ImageType::Png));
        $this->assertSame('b.png', ImageName::clean('a/b.png', ImageType::Png));
        $this->assertSame('b.png', ImageName::clean('a\\b.png', ImageType::Png));
    }

    public function testNameNeverStartsWithADot(): void
    {
        $this->assertSame('htaccess.png', ImageName::clean('.htaccess', ImageType::Png));
    }

    public function testOnlyTheLastExtensionIsReplaced(): void
    {
        // Harmless: the file is served with its row's type and never executed.
        $this->assertSame('x.php.png', ImageName::clean('x.php.png', ImageType::Png));
    }

    public function testExtensionFollowsTheRealType(): void
    {
        $this->assertSame('shot.jpg', ImageName::clean('shot.png', ImageType::Jpeg));
        $this->assertSame('shot.jpg', ImageName::clean('shot.JPEG', ImageType::Jpeg));
    }

    public function testNothingUsableBecomesImage(): void
    {
        $this->assertSame('image.png', ImageName::clean('日本語.png', ImageType::Png));
        $this->assertSame('image.png', ImageName::clean('', ImageType::Png));
    }

    public function testLongNamesAreCutToOneHundred(): void
    {
        $name = ImageName::clean(str_repeat('a', 300) . '.png', ImageType::Png);
        $this->assertSame(100, strlen($name));
        $this->assertStringEndsWith('.png', $name);
    }

    public function testNumberedGoesBeforeTheExtension(): void
    {
        $this->assertSame('a-2.png', ImageName::numbered('a.png', 2));
    }

    public function testNumberedStaysWithinOneHundred(): void
    {
        $name = ImageName::numbered(str_repeat('a', 96) . '.png', 12);
        $this->assertSame(100, strlen($name));
        $this->assertStringEndsWith('-12.png', $name);
    }
}
