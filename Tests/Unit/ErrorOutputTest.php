<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class ErrorOutputTest extends Unit
{
    private function thrownWith(string $secret): \Throwable
    {
        return new \RuntimeException('the database went away');
    }

    public function testTheLogLineNamesTheExceptionAndWhereItWasThrown(): void
    {
        $line = \Security\ErrorOutput::logLine($this->thrownWith('hunter2'));

        $this->assertStringStartsWith('Uncaught RuntimeException: the database went away in ', $line);
        $this->assertStringEndsWith(__FILE__ . ':13', $line);
    }

    public function testTheLogLineLeavesOutTheStackTraceAndItsArguments(): void
    {
        $line = \Security\ErrorOutput::logLine($this->thrownWith('hunter2'));

        $this->assertStringNotContainsString('hunter2', $line);
        $this->assertStringNotContainsString('#0', $line);
        $this->assertStringNotContainsString("\n", $line);
    }

    public function testAJsonResponseGetsAJsonError(): void
    {
        $body = \Security\ErrorOutput::pageFor([
            'X-Frame-Options: DENY',
            'Content-Type: application/json',
        ]);

        $this->assertSame(\Security\ErrorOutput::JSON_PAGE, $body);
        $this->assertSame(['error' => 'Internal error'], json_decode($body, true));
    }

    public function testTheHeaderNameIsReadWithoutCase(): void
    {
        $body = \Security\ErrorOutput::pageFor(['content-type: application/json; charset=utf-8']);

        $this->assertSame(\Security\ErrorOutput::JSON_PAGE, $body);
    }

    public function testAnyOtherResponseGetsThePlainPage(): void
    {
        $this->assertSame(\Security\ErrorOutput::PAGE, \Security\ErrorOutput::pageFor([]));
        $this->assertSame(
            \Security\ErrorOutput::PAGE,
            \Security\ErrorOutput::pageFor(['Content-Type: text/html; charset=UTF-8']),
        );
    }

    public function testAConfigThatSetsDisplayErrorsTrueAsks(): void
    {
        $config = new class {
            public bool $display_errors = true;
        };

        $this->assertTrue(\Security\ErrorOutput::asked($config));
    }

    public function testAConfigThatSetsDisplayErrorsFalseDoesNotAsk(): void
    {
        $config = new class {
            public bool $display_errors = false;
        };

        $this->assertFalse(\Security\ErrorOutput::asked($config));
    }

    public function testAnOlderConfigWithoutThePropertyDoesNotAsk(): void
    {
        $config = new class {
            public string $domain_name = 'example.com';
        };

        $this->assertFalse(\Security\ErrorOutput::asked($config));
    }

    public function testANonBoolValueDoesNotAsk(): void
    {
        $config = new class {
            public string $display_errors = '1';
        };

        $this->assertFalse(\Security\ErrorOutput::asked($config));
    }
}
