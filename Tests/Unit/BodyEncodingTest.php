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
        $this->assertSame('int main(void){}', BodyEncoding::Plain->decode('int main(void){}', 1000));
    }

    public function testBase64RoundTrips(): void
    {
        $source = "#include <stdio.h>\nint main(){ if (x < 0) return 1; }\n";
        $this->assertSame($source, BodyEncoding::Base64->decode(base64_encode($source), 1000));
    }

    public function testBase64ToleratesLineBreaks(): void
    {
        $source  = str_repeat('source code that would trip the WAF; ', 20);
        $wrapped = chunk_split(base64_encode($source), 76, "\n");
        $this->assertSame($source, BodyEncoding::Base64->decode($wrapped, 1000));
    }

    public function testGzipBase64RoundTrips(): void
    {
        $source = "SELECT * FROM users WHERE id = 1; -- a comment about code, not an attack\n";
        $wire   = base64_encode((string) gzencode($source));
        $this->assertSame($source, BodyEncoding::GzipBase64->decode($wire, 1000));
    }

    public function testBadBase64IsRejected(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('body is not valid base64');
        BodyEncoding::Base64->decode('not valid base64 *** @@@', 1000);
    }

    public function testBadGzipIsRejected(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('body is not valid gzip data');
        // Valid base64, but the bytes under it are not a gzip stream.
        BodyEncoding::GzipBase64->decode(base64_encode('plain, not gzipped'), 1000);
    }

    public function testGzipThatExpandsPastTheCapIsRejected(): void
    {
        // 200 KB of one byte gzips to a few hundred bytes: a decompression bomb
        // in miniature. It must be refused without being inflated in full.
        $bomb = base64_encode((string) gzencode(str_repeat('A', 200_000), 9));
        $this->assertLessThan(1000, strlen($bomb));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('body is larger than 1000 bytes once decompressed');
        BodyEncoding::GzipBase64->decode($bomb, 1000);
    }

    public function testGzipExactlyAtTheCapIsAccepted(): void
    {
        $source = str_repeat('x', 1000);
        $wire   = base64_encode((string) gzencode($source));
        $this->assertSame($source, BodyEncoding::GzipBase64->decode($wire, 1000));

        $this->expectException(InvalidInput::class);
        BodyEncoding::GzipBase64->decode($wire, 999);
    }

    public function testErrorsNameTheFieldTheCallerSent(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('message is not valid base64');
        BodyEncoding::Base64->decode('*** nope ***', 1000, 'message');
    }

    public function testTruncatedGzipIsCorruptNotTooBig(): void
    {
        $gzip = (string) gzencode(str_repeat('some source code ', 200));
        $wire = base64_encode(substr($gzip, 0, intdiv(strlen($gzip), 2)));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('body is not valid gzip data');
        BodyEncoding::GzipBase64->decode($wire, 100_000);
    }

    public function testGzipLargerThanOneStepRoundTrips(): void
    {
        // Mixed bytes so the gzip stream spans several 1 KB steps.
        $source = '';
        for ($i = 0; $i < 3000; $i++) {
            $source .= md5((string) $i);
        }
        $wire = base64_encode((string) gzencode($source));
        $this->assertGreaterThan(1024, strlen((string) gzencode($source)));
        $this->assertSame($source, BodyEncoding::GzipBase64->decode($wire, strlen($source)));
    }
}
