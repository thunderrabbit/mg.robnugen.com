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
        'server_domain' => 'example.test',
        'dh_user' => 'exampleuser',
        'web_directory' => 'example.test/public',
    ];

    /**
     * @return array{domain: string, dh_user: ?string, web_directory: ?string, linked_to: ?string}
     */
    private function domain(string $name, ?string $user = 'exampleuser', ?string $dir = null, ?string $linkedTo = null): array
    {
        return ['domain' => $name, 'dh_user' => $user, 'web_directory' => $dir, 'linked_to' => $linkedTo];
    }

    public function testSameUserParentAndANearTwinNameIsACandidate(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [$this->domain('examplet.test')]);

        $this->assertSame('examplet.test', $found[0]['domain']);
        $this->assertContains('same DreamHost user', $found[0]['reasons']);
        $this->assertContains('name differs by one trailing letter', $found[0]['reasons']);
    }

    public function testAReplacedLastLetterCountsAsANearTwin(): void
    {
        $site = ['server_domain' => 'games.test'] + self::SITE;
        $found = TestDomainCandidates::find($site, [$this->domain('gamet.test')]);

        $this->assertContains('name differs by one trailing letter', $found[0]['reasons']);
    }

    public function testOneReasonAloneIsNotEnough(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [$this->domain('unrelated.invalid')]);

        $this->assertSame([], $found);
    }

    public function testADomainLinkedToAnotherSiteIsLeftOut(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [$this->domain('examplet.test', linkedTo: 'other')]);

        $this->assertSame([], $found);
    }

    public function testADomainAlreadyLinkedToThisSiteStaysAndTheServerItselfDoesNot(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [
            $this->domain('example.test', linkedTo: 'example'),
            $this->domain('examplet.test', linkedTo: 'example'),
        ]);

        $this->assertSame(['examplet.test'], array_column($found, 'domain'));
    }

    public function testTheMostReasonsComeFirst(): void
    {
        $found = TestDomainCandidates::find(self::SITE, [
            $this->domain('sibling.test'),
            $this->domain('examplet.test', dir: 'examplet.test/public'),
        ]);

        $this->assertSame(['examplet.test', 'sibling.test'], array_column($found, 'domain'));
    }
}
