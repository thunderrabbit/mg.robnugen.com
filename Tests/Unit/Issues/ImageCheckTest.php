<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Issues\Images\ImageCheck;
use Issues\Images\ImageRefused;
use Issues\Images\ImageType;

/**
 * What may be uploaded: a real PNG, JPEG, GIF or WebP of at most 10 MB,
 * whatever its name claims.
 */
class ImageCheckTest extends Unit
{
    private string $dir;

    protected function _before(): void
    {
        $this->dir = ImageBytes::tempDir();
    }

    /**
     * @return array<string, array{string, ImageType, string}>
     */
    public static function realPictures(): array
    {
        return [
            'png' => [ImageBytes::png(), ImageType::Png, 'a.png'],
            'jpeg' => [ImageBytes::jpeg(), ImageType::Jpeg, 'a.jpg'],
            'gif' => [ImageBytes::gif(), ImageType::Gif, 'a.gif'],
            'webp' => [ImageBytes::webp(), ImageType::Webp, 'a.webp'],
        ];
    }

    /**
     * @dataProvider realPictures
     */
    public function testARealPictureIsDescribed(string $bytes, ImageType $type, string $name): void
    {
        $path = ImageBytes::write($this->dir, 'upload.tmp', $bytes);

        $checked = ImageCheck::file($path, $name);

        $this->assertSame($type, $checked->type);
        $this->assertSame($name, $checked->name);
        $this->assertSame($path, $checked->path);
        $this->assertSame(strlen($bytes), $checked->byteSize);
        $this->assertSame(3, $checked->width);
        $this->assertSame(2, $checked->height);
        $this->assertSame(hash('sha256', $bytes), $checked->sha256);
    }

    public function testNameFollowsTheContentNotTheClaim(): void
    {
        $path = ImageBytes::write($this->dir, 'upload.tmp', ImageBytes::jpeg());

        $this->assertSame('shot.jpg', ImageCheck::file($path, 'shot.png')->name);
    }

    public function testHtmlCalledPngIsRefused(): void
    {
        $path = ImageBytes::write($this->dir, 'upload.tmp', '<html><script>alert(1)</script>');

        $this->expectException(ImageRefused::class);
        $this->expectExceptionMessage('not a PNG, JPEG, GIF or WebP picture');
        ImageCheck::file($path, 'shot.png');
    }

    public function testSvgIsRefused(): void
    {
        $path = ImageBytes::write($this->dir, 'upload.tmp', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->expectException(ImageRefused::class);
        $this->expectExceptionMessage('not a PNG, JPEG, GIF or WebP picture');
        ImageCheck::file($path, 'a.svg');
    }

    public function testOverTenMegabytesIsRefused(): void
    {
        $png = ImageBytes::png();
        $path = ImageBytes::write(
            $this->dir,
            'upload.tmp',
            $png . str_repeat("\0", ImageCheck::MAX_BYTES + 1 - strlen($png)),
        );

        $this->expectException(ImageRefused::class);
        $this->expectExceptionMessage('larger than 10 MB');
        ImageCheck::file($path, 'big.png');
    }

    public function testExactlyTenMegabytesIsAllowed(): void
    {
        $png = ImageBytes::png();
        $path = ImageBytes::write(
            $this->dir,
            'upload.tmp',
            $png . str_repeat("\0", ImageCheck::MAX_BYTES - strlen($png)),
        );

        $this->assertSame(ImageCheck::MAX_BYTES, ImageCheck::file($path, 'big.png')->byteSize);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $path = ImageBytes::write($this->dir, 'upload.tmp', '');

        $this->expectException(ImageRefused::class);
        ImageCheck::file($path, 'a.png');
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(ImageRefused::class);
        ImageCheck::file($this->dir . '/nothing-here', 'a.png');
    }

    public function testADirectoryIsRefused(): void
    {
        $this->expectException(ImageRefused::class);
        ImageCheck::file($this->dir, 'a.png');
    }
}
