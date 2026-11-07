<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\TestDomainCandidates;

/**
 * Hermetic: which domains look like a site's test twin, with made-up names.
 */
final class StandardsTestDomainCandidatesTest extends Unit
{
    private const array SITE = [
        'shortcode' => 'example',
        'server_domain' => 'shop.example.test',
        'dh_user' => 'exampleuser',
        'web_directory' => 'shop.example.test/public',
    ];

    /**
     * @param list<string> $linkedTo
     * @return array{domain: string, dh_user: ?string, web_directory: ?string, linked_to: list<string>}
     */
    private function domain(string $name, ?string $user = 'exampleuser', ?string $dir = null, array $linkedTo = []): array
    {
        return ['domain' => $name, 'dh_user' => $user, 'web_directory' => $dir, 'linked_to' => $linkedTo];
    }

    public function testSameUserParentAndANearTwinNameIsACandidate(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [$this->domain('shopt.example.test')]);

        $this->assertSame('shopt.example.test', $found[0]['domain']);
        $this->assertContains('same DreamHost user', $found[0]['reasons']);
        $this->assertContains('name differs by one trailing letter', $found[0]['reasons']);
    }

    public function testAReplacedLastLetterCountsAsANearTwin(): void
    {
        $site = ['server_domain' => 'cards.example.test'] + self::SITE;
        $found = TestDomainCandidates::find($site, [$this->domain('cardt.example.test')]);

        $this->assertContains('name differs by one trailing letter', $found[0]['reasons']);
    }

    public function testOneReasonAloneIsNotEnough(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [$this->domain('unrelated.invalid')]);

        $this->assertSame([], $found);
    }

    public function testADomainLinkedToAnotherSiteIsLeftOut(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [$this->domain('shopt.example.test', linkedTo: ['other'])]);

        $this->assertSame([], $found);
    }

    public function testADomainAlreadyLinkedToThisSiteStaysAndTheServerItselfDoesNot(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [
            $this->domain('shop.example.test', linkedTo: ['example']),
            $this->domain('shopt.example.test', linkedTo: ['example']),
        ]);

        $this->assertSame(['shopt.example.test'], array_column($found, 'domain'));
    }

    public function testTheMostReasonsComeFirst(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [
            $this->domain('blog.example.test'),
            $this->domain('shopt.example.test', dir: 'shopt.example.test/public'),
        ]);

        $this->assertSame(['shopt.example.test', 'blog.example.test'], array_column($found, 'domain'));
    }

    public function testTwoApexDomainsOnTheSameTldAreNotSameParent(): void
    {
        $site = ['server_domain' => 'example.test', 'web_directory' => null] + self::SITE;
        $found = TestDomainCandidates::find($site, [$this->domain('sibling.test')]);

        $this->assertSame([], $found);
    }

    public function testADomainAlsoLinkedToAnotherSiteUnderAPathIsLeftOut(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [
            $this->domain('shopt.example.test', linkedTo: ['example', 'other']),
        ]);

        $this->assertSame([], $found);
    }
}
