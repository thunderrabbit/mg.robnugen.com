<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

/**
 * Real, tiny picture files for the image tests, drawn with GD where the runner's GD can. A different
 * seed gives different bytes, so two pictures can share a name and still differ.
 *
 * Not named *Test, so Codeception treats it as support rather than a suite.
 */
final class ImageBytes
{
    public static function png(int $seed = 0): string
    {
        return self::draw($seed, imagepng(...));
    }

    /**
     * A 3x2 JPEG, kept as bytes because the test runner's GD cannot write one.
     */
    public static function jpeg(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIx' .
            'wcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy' .
            'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAACAAMDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAw' .
            'QFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkK' .
            'FhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmq' .
            'KjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEB' .
            'AQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRob' .
            'HBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOE' .
            'hYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+P' .
            'n6/9oADAMBAAIRAxEAPwDyWiiiv1I80//Z',
            true,
        );
    }

    public static function gif(int $seed = 0): string
    {
        return self::draw($seed, imagegif(...));
    }

    /**
     * A 3x2 WebP, kept as bytes for the same reason.
     */
    public static function webp(): string
    {
        return (string) base64_decode(
            'UklGRjoAAABXRUJQVlA4IC4AAACQAQCdASoDAAIAAUAmJaACdLoAA5gA/vp5X/9+0v++0v++0v9e34rfUDNIAAAA',
            true,
        );
    }

    /**
     * A fresh private directory under the system temp directory.
     */
    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mg-image-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        return $dir;
    }

    public static function write(string $dir, string $name, string $bytes): string
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    /**
     * @param \Closure(\GdImage): bool $encode
     */
    private static function draw(int $seed, \Closure $encode): string
    {
        $image = imagecreatetruecolor(3, 2);
        $colour = imagecolorallocate($image, $seed & 0xFF, ($seed >> 8) & 0xFF, 200);
        imagefill($image, 0, 0, (int) $colour);
        ob_start();
        $encode($image);
        return (string) ob_get_clean();
    }
}
