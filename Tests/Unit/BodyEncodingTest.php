<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Input\BodyEncoding;
use Input\InvalidInput;

/**
 * BodyEncoding turns a wire string back into the plain text it stands for.
 * It exists so an agent behind a request firewall can post source code whose
 * syntax would otherwise trip the WAF: Base64 (or gzip+Base64) bytes carry no
 * attack signature, and mg decodes them before storing. Nothing is secret.
 */
final class BodyEncodingTest extends Unit
{
    public function testPlainIsTheBodyItself(): void
    {
        $this->assertSame('int main(void){}', BodyEncoding::Plain->decode('int main(void){}'));
    }

    public function testBase64RoundTrips(): void
    {
        $source = "#include <stdio.h>\nint main(){ if (x < 0) return 1; }\n";
        $this->assertSame($source, BodyEncoding::Base64->decode(base64_encode($source)));
    }

    public function testBase64ToleratesLineBreaks(): void
    {
        $source  = str_repeat('source code that would trip the WAF; ', 20);
        $wrapped = chunk_split(base64_encode($source), 76, "\n");
        $this->assertSame($source, BodyEncoding::Base64->decode($wrapped));
    }

    public function testGzipBase64RoundTrips(): void
    {
        $source = "SELECT * FROM users WHERE id = 1; -- a comment about code, not an attack\n";
        $wire   = base64_encode((string) gzencode($source));
        $this->assertSame($source, BodyEncoding::GzipBase64->decode($wire));
    }

    public function testBadBase64IsRejected(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('body is not valid base64');
        BodyEncoding::Base64->decode('not valid base64 *** @@@');
    }

    public function testBadGzipIsRejected(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('body is not valid gzip data');
        // Valid base64, but the bytes under it are not a gzip stream.
        BodyEncoding::GzipBase64->decode(base64_encode('plain, not gzipped'));
    }
}
