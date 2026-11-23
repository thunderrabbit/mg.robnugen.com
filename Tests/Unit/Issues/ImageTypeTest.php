<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Issues\Images\ImageType;

class ImageTypeTest extends Unit
{
    public function testEachTypeHasOneExtension(): void
    {
        $this->assertSame('png', ImageType::Png->extension());
        $this->assertSame('jpg', ImageType::Jpeg->extension());
        $this->assertSame('gif', ImageType::Gif->extension());
        $this->assertSame('webp', ImageType::Webp->extension());
    }

    public function testOnlyTheFourTypesAreKnown(): void
    {
        $this->assertSame(ImageType::Webp, ImageType::fromMime('image/webp'));
        $this->assertNull(ImageType::fromMime('image/svg+xml'));
        $this->assertNull(ImageType::fromMime('text/html'));
        $this->assertNull(ImageType::fromMime(''));
    }
}
