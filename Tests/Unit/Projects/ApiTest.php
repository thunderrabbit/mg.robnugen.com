<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use Codeception\Test\Unit;
use Input\InvalidInput;
use Issues\AccessException;
use Issues\ConflictException;
use Issues\NotFoundException;
use Issues\ValidationException;
use Projects\Api;

/**
 * Hermetic unit tests for Projects\Api::run, which turns what a
 * ProjectService call throws into the REST API's JSON error and status code.
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
            [new InvalidInput('name is required'), 400],
            [new ValidationException('Name too long'), 400],
            [new NotFoundException('Project not found'), 404],
            [new AccessException('Not your project'), 403],
            [new ConflictException('Name already used'), 409],
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
