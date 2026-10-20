<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Db\Enum\IssuePriority;
use Input\Absent;
use Input\BodyEncoding;
use Input\Input;
use Input\InvalidInput;
use Input\Length;

/**
 * Hermetic unit tests for Input\Input, the strict reader for request fields.
 * A wrong or missing field is the caller's mistake, so it throws InvalidInput
 * with a message an agent can act on; each surface turns that into a 400, a
 * JSON-RPC error or a form message.
 */
final class InputTest extends Unit
{
    // === positiveId() ===

    public function testPositiveIdAcceptsIntsAndDigitStrings(): void
    {
        $this->assertSame(7, Input::positiveId(['issue_id' => 7], 'issue_id'));
        $this->assertSame(42, Input::positiveId(['issue_id' => '42'], 'issue_id'));
    }

    public function testPositiveIdSaysWhatIsWrong(): void
    {
        $this->assertInvalid('issue_id is required', static fn() => Input::positiveId([], 'issue_id'));
        $this->assertInvalid('issue_id is required', static fn() => Input::positiveId(['issue_id' => null], 'issue_id'));
        foreach ([0, -1, '0', '', '4.2', 'x', 4.0, true, [1]] as $bad) {
            $this->assertInvalid(
                'issue_id must be a positive integer',
                static fn() => Input::positiveId(['issue_id' => $bad], 'issue_id'),
            );
        }
    }

    // === nullableId() ===

    public function testNullableIdSeparatesAbsentClearedAndSet(): void
    {
        $this->assertSame(Absent::Field, Input::nullableId([], 'assignee_aiu'));
        foreach ([null, 0, '0', '', 'null'] as $clear) {
            $this->assertNull(Input::nullableId(['assignee_aiu' => $clear], 'assignee_aiu'));
        }
        $this->assertSame(35, Input::nullableId(['assignee_aiu' => '35'], 'assignee_aiu'));
        $this->assertInvalid(
            'assignee_aiu must be a positive integer, or 0 or null to clear it',
            static fn() => Input::nullableId(['assignee_aiu' => 'x'], 'assignee_aiu'),
        );
    }

    // === int() / flag() ===

    public function testIntFallsBackToItsDefaultOnlyWhenAbsent(): void
    {
        $this->assertSame(100, Input::int([], 'limit', 100));
        $this->assertSame(100, Input::int(['limit' => null], 'limit', 100));
        $this->assertSame(20, Input::int(['limit' => '20'], 'limit', 100));
        $this->assertSame(-1, Input::int(['offset' => -1], 'offset', 0));
        $this->assertInvalid('limit must be an integer', static fn() => Input::int(['limit' => 'lots'], 'limit', 100));
    }

    public function testFlagAcceptsCommonSpellings(): void
    {
        $this->assertFalse(Input::flag([], 'include_terminal'));
        foreach ([true, 1, '1', 'true'] as $on) {
            $this->assertTrue(Input::flag(['include_terminal' => $on], 'include_terminal'));
        }
        foreach ([false, 0, '0', 'false', ''] as $off) {
            $this->assertFalse(Input::flag(['include_terminal' => $off], 'include_terminal'));
        }
        $this->assertInvalid(
            'include_terminal must be 1 or 0',
            static fn() => Input::flag(['include_terminal' => 'maybe'], 'include_terminal'),
        );
    }

    // === text() ===

    public function testTextTrimsAndRequiresSomething(): void
    {
        $this->assertSame('Fix it', Input::text(['title' => "  Fix it \n"], 'title', 255));
        $this->assertInvalid('title is required', static fn() => Input::text([], 'title', 255));
        $this->assertInvalid('title is required', static fn() => Input::text(['title' => '   '], 'title', 255));
        $this->assertInvalid('title must be a string', static fn() => Input::text(['title' => 5], 'title', 255));
    }

    public function testTextCountsCharactersOrBytes(): void
    {
        $this->assertSame(str_repeat('あ', 255), Input::text(['title' => str_repeat('あ', 255)], 'title', 255));
        $this->assertInvalid(
            'title must be 255 characters or fewer',
            static fn() => Input::text(['title' => str_repeat('a', 256)], 'title', 255),
        );
        $this->assertInvalid(
            'body must be 6 bytes or fewer',
            static fn() => Input::text(['body' => 'あああ'], 'body', 6, Length::Bytes),
        );
        $this->assertSame('ああ', Input::text(['body' => 'ああ'], 'body', 6, Length::Bytes));
    }

    // === optionalText() ===

    public function testOptionalTextSeparatesAbsentClearedAndSet(): void
    {
        $this->assertSame(Absent::Field, Input::optionalText([], 'description', 65535));
        $this->assertNull(Input::optionalText(['description' => null], 'description', 65535));
        $this->assertNull(Input::optionalText(['description' => ''], 'description', 65535));
        $this->assertSame(" keep\n", Input::optionalText(['description' => " keep\n"], 'description', 65535));
        $this->assertSame('trimmed', Input::optionalText(['note' => ' trimmed '], 'note', 255, trim: true));
        $this->assertNull(Input::optionalText(['note' => '   '], 'note', 255, trim: true));
        $this->assertInvalid(
            'note must be 3 characters or fewer',
            static fn() => Input::optionalText(['note' => 'four'], 'note', 3),
        );
    }

    // === enum() ===

    public function testEnumReadsACaseOrAbsent(): void
    {
        $this->assertSame(IssuePriority::High, Input::enum(['priority' => 'high'], 'priority', IssuePriority::class));
        $this->assertSame(Absent::Field, Input::enum([], 'priority', IssuePriority::class));
        $this->assertInvalid(
            "priority must be 'low', 'normal', or 'high'",
            static fn() => Input::enum(['priority' => 'urgent'], 'priority', IssuePriority::class),
        );
        $this->assertInvalid(
            "priority must be 'low', 'normal', or 'high'",
            static fn() => Input::enum(['priority' => 3], 'priority', IssuePriority::class),
        );
    }

    // === encodedText() ===

    public function testEncodedTextDefaultsToPlain(): void
    {
        $this->assertSame(
            'Fix it',
            Input::encodedText(['body' => "  Fix it \n"], 'body', 'encoding', 255),
        );
    }

    public function testEncodedTextDecodesBase64ThenAppliesTheLimit(): void
    {
        $source = "if (x < 0) { drop(it); }";
        $this->assertSame(
            $source,
            Input::encodedText(
                ['body' => base64_encode($source), 'encoding' => 'base64'],
                'body',
                'encoding',
                255,
                Length::Bytes,
            ),
        );
    }

    public function testEncodedTextDecodesGzipBase64(): void
    {
        $source = "SELECT 1; -- code, not an attack";
        $this->assertSame(
            $source,
            Input::encodedText(
                ['body' => base64_encode((string) gzencode($source)), 'encoding' => 'gzip+base64'],
                'body',
                'encoding',
                255,
                Length::Bytes,
            ),
        );
    }

    public function testEncodedTextLimitsTheDecodedLengthNotTheWire(): void
    {
        // 300 plain bytes base64-encode to ~400 wire bytes; the limit is on the
        // decoded text, so a 255-byte limit must reject the 300-byte body.
        $source = str_repeat('a', 300);
        $this->assertInvalid(
            'body must be 255 bytes or fewer',
            static fn() => Input::encodedText(
                ['body' => base64_encode($source), 'encoding' => 'base64'],
                'body',
                'encoding',
                255,
                Length::Bytes,
            ),
        );
    }

    public function testEncodedTextRejectsAnEmptyDecodedBody(): void
    {
        $this->assertInvalid(
            'body is required',
            static fn() => Input::encodedText(['body' => base64_encode('   '), 'encoding' => 'base64'], 'body', 'encoding', 255),
        );
        $this->assertInvalid(
            'body is required',
            static fn() => Input::encodedText([], 'body', 'encoding', 255),
        );
    }

    public function testEncodedTextRefusesAGzipBombBeforeInflatingIt(): void
    {
        $bomb = base64_encode((string) gzencode(str_repeat('A', 5_000_000), 9));
        $this->assertInvalid(
            'body is larger than 255 bytes once decompressed',
            static fn() => Input::encodedText(
                ['body' => $bomb, 'encoding' => 'gzip+base64'],
                'body',
                'encoding',
                255,
                Length::Bytes,
            ),
        );
    }

    public function testByteCeilingAllowsFourBytesPerCharacter(): void
    {
        $this->assertSame(255, Length::Bytes->byteCeiling(255));
        $this->assertSame(1020, Length::Characters->byteCeiling(255));
    }

    public function testEncodedTextReportsABadEncodingName(): void
    {
        $this->assertInvalid(
            "encoding must be 'plain', 'base64', or 'gzip+base64'",
            static fn() => Input::encodedText(['body' => 'x', 'encoding' => 'rot13'], 'body', 'encoding', 255),
        );
    }

    public function testEncodedTextReportsBadBase64(): void
    {
        $this->assertInvalid(
            'body is not valid base64',
            static fn() => Input::encodedText(['body' => '***', 'encoding' => 'base64'], 'body', 'encoding', 255),
        );
    }

    // === encoding() ===

    public function testEncodingDefaultsToPlainAndReadsANamedOne(): void
    {
        $this->assertSame(BodyEncoding::Plain, Input::encoding([], 'encoding'));
        $this->assertSame(BodyEncoding::Plain, Input::encoding(['encoding' => 'plain'], 'encoding'));
        $this->assertSame(BodyEncoding::Base64, Input::encoding(['encoding' => 'base64'], 'encoding'));
        $this->assertSame(BodyEncoding::GzipBase64, Input::encoding(['encoding' => 'gzip+base64'], 'encoding'));
        $this->assertInvalid(
            "encoding must be 'plain', 'base64', or 'gzip+base64'",
            static fn() => Input::encoding(['encoding' => 'rot13'], 'encoding'),
        );
    }

    // === encodedOptionalText() ===

    public function testEncodedOptionalTextSeparatesAbsentClearedAndSet(): void
    {
        $read = static fn(array $in) => Input::encodedOptionalText($in, 'description', 'encoding', 100, Length::Bytes);

        $this->assertSame(Absent::Field, $read([]));
        $this->assertSame(Absent::Field, $read(['encoding' => 'base64']));
        $this->assertNull($read(['description' => null]));
        $this->assertNull($read(['description' => '']));
        $this->assertNull($read(['description' => '', 'encoding' => 'base64']));
        $this->assertSame('   ', $read(['description' => base64_encode('   '), 'encoding' => 'base64']));
    }

    public function testEncodedOptionalTextKeepsPlainBehaviourExactly(): void
    {
        $this->assertSame(
            " keep\n",
            Input::encodedOptionalText(['description' => " keep\n"], 'description', 'encoding', 100),
        );
        $this->assertInvalid(
            'description must be 3 characters or fewer',
            static fn() => Input::encodedOptionalText(['description' => 'four'], 'description', 'encoding', 3),
        );
        $this->assertInvalid(
            'description must be a string',
            static fn() => Input::encodedOptionalText(['description' => 5], 'description', 'encoding', 3),
        );
    }

    public function testEncodedOptionalTextDecodesWithoutTrimmingAndLimitsTheDecodedText(): void
    {
        $source = "  if (n < 0) { drop(it); }\n";
        $this->assertSame(
            $source,
            Input::encodedOptionalText(
                ['description' => base64_encode($source), 'encoding' => 'base64'],
                'description',
                'encoding',
                100,
                Length::Bytes,
            ),
        );
        $this->assertSame(
            $source,
            Input::encodedOptionalText(
                ['description' => base64_encode((string) gzencode($source)), 'encoding' => 'gzip+base64'],
                'description',
                'encoding',
                100,
                Length::Bytes,
            ),
        );
        $this->assertInvalid(
            'description must be 10 bytes or fewer',
            static fn() => Input::encodedOptionalText(
                ['description' => base64_encode($source), 'encoding' => 'base64'],
                'description',
                'encoding',
                10,
                Length::Bytes,
            ),
        );
    }

    public function testEncodedOptionalTextRefusesAGzipBomb(): void
    {
        $this->assertInvalid(
            'description is larger than 100 bytes once decompressed',
            static fn() => Input::encodedOptionalText(
                ['description' => base64_encode((string) gzencode(str_repeat('A', 5_000_000), 9)), 'encoding' => 'gzip+base64'],
                'description',
                'encoding',
                100,
                Length::Bytes,
            ),
        );
    }

    // === json() ===

    public function testJsonReadsTheFieldsOfABody(): void
    {
        $this->assertSame(['issue_id' => 4, 'body' => 'noted'], Input::json('{"issue_id":4,"body":"noted"}'));
        $this->assertSame([], Input::json('{}'));
    }

    public function testJsonRefusesABodyThatCarriesNoFields(): void
    {
        foreach (['', '   ', 'null', '4', '"text"', 'true', '{"broken"'] as $bad) {
            $this->assertInvalid('a JSON object body is required', static fn() => Input::json($bad));
        }
    }

    private function assertInvalid(string $message, \Closure $read): void
    {
        try {
            $read();
            $this->fail("Expected InvalidInput: {$message}");
        } catch (InvalidInput $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
