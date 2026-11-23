<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Input\InvalidInput;
use Issues\AccessException;
use Issues\Api;
use Issues\ConflictException;
use Issues\NotFoundException;
use Issues\Page;
use Issues\ValidationException;

/**
 * Hermetic unit tests for Issues\Api: run() turns what an IssueService call
 * throws into the REST API's JSON error and status code, and page() is the
 * envelope of a list read.
 */
final class ApiTest extends Unit
{
    protected function _after(): void
    {
        http_response_code(200);
    }

    public function testACallThatThrowsNothingPrintsOnlyItsOwnOutput(): void
    {
        http_response_code(200);

        $this->assertSame('{"ok":true}', $this->printed(static function (): void {
            echo '{"ok":true}';
        }));
        $this->assertSame(200, http_response_code());
    }

    public function testEachExceptionGetsItsStatusCodeAndMessage(): void
    {
        $cases = [
            [new InvalidInput('issue_id is required'), 400],
            [new ValidationException('Title too long'), 400],
            [new NotFoundException('Issue not found'), 404],
            [new AccessException('Not your issue'), 403],
            [new ConflictException('Already linked'), 409],
        ];

        foreach ($cases as [$exception, $code]) {
            $printed = $this->printed(static function () use ($exception): never {
                throw $exception;
            });

            $this->assertSame(json_encode(['error' => $exception->getMessage()]), $printed);
            $this->assertSame($code, http_response_code());
        }
    }

    public function testAnyOtherExceptionIsNotCaught(): void
    {
        ob_start();
        try {
            Api::run(static function (): never {
                throw new \RuntimeException('database is away');
            });
            $this->fail('RuntimeException was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('database is away', $e->getMessage());
        } finally {
            ob_end_clean();
        }
    }

    public function testPageIsTheListEnvelopeUnderTheGivenKey(): void
    {
        $item = new class implements \JsonSerializable {
            public function jsonSerialize(): string
            {
                return 'a comment';
            }
        };
        $items = [$item, $item];

        $this->assertSame(
            ['comments' => $items, 'total' => 12, 'limit' => 2, 'offset' => 4],
            Api::page('comments', new Page($items, total: 12, limit: 2, offset: 4)),
        );
    }

    /**
     * @param \Closure(): void $call
     */
    private function printed(\Closure $call): string
    {
        ob_start();
        try {
            Api::run($call);
        } finally {
            $printed = (string) ob_get_clean();
        }

        return $printed;
    }
}
