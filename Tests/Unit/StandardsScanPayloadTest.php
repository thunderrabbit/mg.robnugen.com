<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\Outcome;
use Inventory\Standards\ScanPayload;
use Inventory\Standards\StandardsFormatException;

/**
 * Hermetic: the one JSON document the scanner on Lemur 13 sends, checked
 * before anything is written. Sites are made up.
 */
final class StandardsScanPayloadTest extends Unit
{
    private const string SHA = '0123456789abcdef0123456789abcdef01234567';

    /**
     * @return array<string, mixed>
     */
    private function good(): array
    {
        return [
            'scanned_at_utc' => '2026-11-07 01:20:00',
            'steps_commit' => self::SHA,
            'files' => [
                'template/_standard.md' => "---\nname: Template sites\n---\n",
                'template/phpstan/_track.md' => "---\nname: PHPStan\nposition: 40\n---\n",
                'template/phpstan/phpstan_level_10_no_baseline.md' =>
                    "---\ntitle: Level 10, no baseline\nposition: 10\nstatus: proven\n"
                    . "whose_move: agent\ncheck: phpstan_top\n---\nWhy.\n",
            ],
            'sites' => [
                [
                    'shortcode' => 'mg',
                    'branch' => 'master',
                    'commit' => self::SHA,
                    'server_branch' => 'master',
                    'server_commit' => self::SHA,
                    'skipped_reason' => null,
                    'results' => [
                        [
                            'step' => 'phpstan_level_10_no_baseline',
                            'outcome' => 'pass',
                            'detail' => 'level 10, run clean',
                        ],
                    ],
                ],
                [
                    'shortcode' => 'example-games',
                    'branch' => 'example-topic',
                    'commit' => null,
                    'skipped_reason' => 'on branch example-topic',
                    'results' => [],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function refuses(array $data, string $pattern): void
    {
        try {
            ScanPayload::fromArray($data);
        } catch (StandardsFormatException $e) {
            $this->assertMatchesRegularExpression($pattern, $e->getMessage());
            return;
        }
        $this->fail('The scan document was accepted.');
    }

    /**
     * The good document with one field of one site changed.
     *
     * @return array<string, mixed>
     */
    private function withSite(int $index, string $key, mixed $value): array
    {
        $data = $this->good();
        $sites = $data['sites'];
        $this->assertIsArray($sites);
        $site = $sites[$index];
        $this->assertIsArray($site);
        $site[$key] = $value;
        $sites[$index] = $site;
        $data['sites'] = $sites;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepResult(string $outcome, string $detail): array
    {
        return ['step' => 'phpstan_level_10_no_baseline', 'outcome' => $outcome, 'detail' => $detail];
    }

    public function testAcceptsAScannedAndASkippedSite(): void
    {
        $payload = ScanPayload::fromArray($this->good());

        $this->assertSame('2026-11-07 01:20:00', $payload->scannedAtUtc);
        $this->assertSame(self::SHA, $payload->stepsCommit);
        $this->assertSame('template', $payload->tree->standards[0]->slug);
        $this->assertSame('mg', $payload->sites[0]['shortcode']);
        $this->assertSame('master', $payload->sites[0]['branch']);
        $this->assertNull($payload->sites[0]['skippedReason']);
        $this->assertSame('phpstan_level_10_no_baseline', $payload->sites[0]['results'][0]['step']);
        $this->assertSame(Outcome::Pass, $payload->sites[0]['results'][0]['outcome']);
        $this->assertSame('level 10, run clean', $payload->sites[0]['results'][0]['detail']);
        $this->assertSame('on branch example-topic', $payload->sites[1]['skippedReason']);
        $this->assertSame([], $payload->sites[1]['results']);
        $this->assertSame('master', $payload->sites[0]['serverBranch']);
        $this->assertSame(self::SHA, $payload->sites[0]['serverCommit']);
        $this->assertNull($payload->sites[1]['serverCommit'], 'a site may leave the server fields out');
    }

    public function testRefusesAScannedSiteNotAtItsServersCommit(): void
    {
        $this->refuses($this->withSite(0, 'server_commit', str_repeat('b', 40)), '/server runs/');
        $this->refuses($this->withSite(0, 'server_commit', null), '/server runs/');
    }

    public function testAScannedCheckoutMayBeOnADetachedHead(): void
    {
        $payload = ScanPayload::fromArray($this->withSite(0, 'server_branch', null));
        $this->assertNull($payload->sites[0]['serverBranch']);

        $payload = ScanPayload::fromArray($this->withSite(0, 'branch', null));
        $this->assertNull($payload->sites[0]['branch']);
    }

    public function testRefusesAServerBranchThatIsNotABranchName(): void
    {
        $this->refuses($this->withSite(0, 'server_branch', 'master; rm -rf'), '/server_branch/');
    }

    public function testAPayloadWithNoSitesIsFine(): void
    {
        $data = $this->good();
        $data['sites'] = [];

        $this->assertSame([], ScanPayload::fromArray($data)->sites);
    }

    public function testAScannedSiteMayHaveNothingToReport(): void
    {
        $payload = ScanPayload::fromArray($this->withSite(0, 'results', []));

        $this->assertSame([], $payload->sites[0]['results']);
    }

    public function testRefusesASkippedSiteThatAlsoCarriesResults(): void
    {
        $this->refuses($this->withSite(1, 'results', [$this->stepResult('pass', '')]), '/example-games/');
    }

    public function testRefusesAScannedSiteWithNoCommit(): void
    {
        $this->refuses($this->withSite(0, 'commit', null), '/commit/');
    }

    public function testRefusesAnOutcomeItDoesNotKnow(): void
    {
        $this->refuses($this->withSite(0, 'results', [$this->stepResult('maybe', '')]), '/outcome/');
    }

    public function testRefusesADetailOver255Characters(): void
    {
        $this->refuses($this->withSite(0, 'results', [$this->stepResult('fail', str_repeat('x', 256))]), '/detail/');
    }

    public function testRefusesAShortcodeWithBadCharacters(): void
    {
        $this->refuses($this->withSite(0, 'shortcode', 'Example_Games'), '/shortcode/');
    }

    public function testRefusesTheSameSiteTwice(): void
    {
        $this->refuses($this->withSite(1, 'shortcode', 'mg'), '/mg/');
    }

    public function testRefusesTheSameStepTwiceForOneSite(): void
    {
        $this->refuses(
            $this->withSite(0, 'results', [$this->stepResult('pass', ''), $this->stepResult('fail', '')]),
            '/phpstan_level_10_no_baseline/',
        );
    }

    public function testRefusesATimeThatIsNotADate(): void
    {
        $data = $this->good();
        $data['scanned_at_utc'] = '2026-13-45 01:20:00';

        $this->refuses($data, '/scanned_at_utc/');
    }

    public function testRefusesAStepsCommitThatIsNotAHash(): void
    {
        $data = $this->good();
        $data['steps_commit'] = 'master';

        $this->refuses($data, '/steps_commit/');
    }

    public function testRefusesAMissingKey(): void
    {
        $data = $this->good();
        unset($data['files']);

        $this->refuses($data, '/files/');
    }

    public function testRefusesWhatTheStepTreeRefuses(): void
    {
        $data = $this->good();
        $data['files'] = ['template/phpstan/_track.md' => "---\nname: PHPStan\nposition: 40\n---\n"];

        $this->refuses($data, '#template/_standard\.md#');
    }

    public function testRefusesADocumentWithNoStepFiles(): void
    {
        // An empty tree would otherwise look like every step had been deleted.
        $data = $this->good();
        $data['files'] = [];
        $data['sites'] = [];

        $this->refuses($data, '/no step files/');
    }
}
