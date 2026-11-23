<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Auth\AuthCode;
use Codeception\Test\Unit;

/**
 * Hermetic unit tests for Auth\AuthCode::redirectUrl, the address the browser
 * is sent back to with a new authorization code. issueAndRedirect itself
 * writes to the database and ends the request, so it is not run here.
 */
final class AuthCodeTest extends Unit
{
    public function testTheCodeAndStateAreAddedToTheRedirectUri(): void
    {
        $this->assertSame(
            'https://client.example/callback?code=abc123&state=xyz',
            AuthCode::redirectUrl('https://client.example/callback', 'abc123', 'xyz'),
        );
    }

    public function testARedirectUriThatAlreadyHasAQueryKeepsIt(): void
    {
        $this->assertSame(
            'https://client.example/callback?tenant=7&code=abc123&state=xyz',
            AuthCode::redirectUrl('https://client.example/callback?tenant=7', 'abc123', 'xyz'),
        );
    }

    public function testAnAppSchemeRedirectWorksTheSameWay(): void
    {
        $this->assertSame(
            'someapp://oauth/callback?code=abc123&state=xyz',
            AuthCode::redirectUrl('someapp://oauth/callback', 'abc123', 'xyz'),
        );
    }

    public function testTheStateIsUrlEncodedSoItCannotAddParameters(): void
    {
        $this->assertSame(
            'https://client.example/callback?code=abc123&state=a%26code%3Dother%23frag+b',
            AuthCode::redirectUrl('https://client.example/callback', 'abc123', 'a&code=other#frag b'),
        );
    }

    public function testAnEmptyStateIsStillSent(): void
    {
        $this->assertSame(
            'https://client.example/callback?code=abc123&state=',
            AuthCode::redirectUrl('https://client.example/callback', 'abc123', ''),
        );
    }
}
