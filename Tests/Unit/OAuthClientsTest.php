<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\OAuthClients;
use Codeception\Test\Unit;

class OAuthClientsTest extends Unit
{
    public function testBgbellsRedirectPairing(): void
    {
        $this->assertTrue(OAuthClients::redirectAllowed('bgbells', 'bgbells://oauth/callback'));
        $this->assertFalse(OAuthClients::redirectAllowed('bgbells', 'https://claude.ai/api/mcp/auth_callback'));
        $this->assertFalse(OAuthClients::redirectAllowed('claude-x', 'bgbells://oauth/callback'));
        $this->assertTrue(OAuthClients::redirectAllowed('claude-x', 'https://claude.ai/api/mcp/auth_callback'));
        $this->assertTrue(OAuthClients::redirectAllowed('claude-x', 'https://claude.com/api/mcp/auth_callback'));
        $this->assertFalse(OAuthClients::redirectAllowed('bgbells', 'bgbells://oauth/callback/evil'));
        $this->assertFalse(OAuthClients::redirectAllowed('claude-x', 'https://evil.example/cb'));
    }

    public function testPinnedActorAndConsent(): void
    {
        $this->assertSame('BG Bells', OAuthClients::pinnedActorName('bgbells'));
        $this->assertNull(OAuthClients::pinnedActorName('other'));
        $this->assertFalse(OAuthClients::remembersConsent('bgbells'));
        $this->assertTrue(OAuthClients::remembersConsent('other'));
        $this->assertSame('BG Bells', OAuthClients::displayName('bgbells'));
        $this->assertNull(OAuthClients::displayName('other'));
    }
}
