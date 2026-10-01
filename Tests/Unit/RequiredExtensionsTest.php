<?php

namespace Tests\Unit;

use Codeception\Test\Unit;
use Startup\RequiredExtensions;

/**
 * Hermetic unit tests for the startup extension check.
 */
class RequiredExtensionsTest extends Unit
{
    public function testNothingMissingWhenEveryExtensionIsLoaded(): void
    {
        $this->assertSame([], RequiredExtensions::missing(['session', 'curl'], fn (string $e): bool => true));
    }

    public function testListsOnlyTheMissingOnesInOrder(): void
    {
        $loaded = fn (string $e): bool => $e === 'curl';

        $this->assertSame(
            ['session', 'sodium'],
            RequiredExtensions::missing(['session', 'curl', 'sodium'], $loaded),
        );
    }

    public function testMessageNamesEveryMissingExtension(): void
    {
        $message = RequiredExtensions::message(['session', 'sodium']);

        $this->assertStringContainsString('session, sodium', $message);
    }

    public function testTheTestRunnerHasEveryRequiredExtension(): void
    {
        $this->assertSame([], RequiredExtensions::missing(RequiredExtensions::REQUIRED, extension_loaded(...)));
    }
}
