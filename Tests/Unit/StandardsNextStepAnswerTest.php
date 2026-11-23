<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\NextStepAnswer;
use Inventory\Standards\SiteFound;

/**
 * Hermetic: the one JSON object the next-step command prints. Sites are made up.
 */
final class StandardsNextStepAnswerTest extends Unit
{
    private const string SHA = '0123456789abcdef0123456789abcdef01234567';

    /**
     * One site as SiteStore::forScanner() lists it.
     *
     * @return array{shortcode: string, standard: string, checkout_path: ?string, server: ?array{ssh_alias: string, directory: string}, server_problem: ?string, php_current: ?string, test: ?array{domain: string, php_version: ?string, ssh_alias: ?string, directory: ?string}, upgrade_branch: ?string}
     */
    private function facts(string $shortcode): array
    {
        return [
            'shortcode' => $shortcode,
            'standard' => 'template',
            'checkout_path' => 'rob/example.test',
            'server' => ['ssh_alias' => 'example', 'directory' => 'example.test'],
            'server_problem' => null,
            'php_current' => '8.2',
            'test' => [
                'domain' => 'test.example.test',
                'php_version' => '8.5',
                'ssh_alias' => 'example',
                'directory' => 'test.example.test',
            ],
            'upgrade_branch' => 'feature/next',
        ];
    }

    private function found(string $shortcode): SiteFound
    {
        return new SiteFound([
            'shortcode' => $shortcode,
            'standard' => 'template',
            'standing' => ['mark' => 'check', 'text' => 'up to date'],
            'php_current' => '8.2',
            'test_php' => '8.5',
            'last_checked_utc' => '2026-11-07 03:12',
            'skipped_reason' => null,
            'open_steps' => [],
        ], []);
    }

    public function testAFoundSiteComesWithItsOwnFactsAndTheScansCommit(): void
    {
        $found = $this->found('example');

        $answer = NextStepAnswer::of($found, [$this->facts('mg'), $this->facts('example')], self::SHA);

        $this->assertSame(['site', 'shortcodes', 'facts', 'steps_commit'], array_keys($answer));
        $this->assertSame($found->site, $answer['site']);
        $this->assertSame([], $answer['shortcodes']);
        $this->assertSame($this->facts('example'), $answer['facts']);
        $this->assertSame(self::SHA, $answer['steps_commit']);
    }

    public function testNoSiteHasNoFactsButStillTheScansCommit(): void
    {
        $answer = NextStepAnswer::of(new SiteFound(null, []), [$this->facts('mg')], self::SHA);

        $this->assertNull($answer['site']);
        $this->assertSame([], $answer['shortcodes']);
        $this->assertNull($answer['facts']);
        $this->assertSame(self::SHA, $answer['steps_commit']);
    }

    public function testSeveralSitesAreNamedAndNoneIsPicked(): void
    {
        $answer = NextStepAnswer::of(
            new SiteFound(null, ['example', 'example-blog']),
            [$this->facts('example'), $this->facts('example-blog')],
            self::SHA,
        );

        $this->assertNull($answer['site']);
        $this->assertSame(['example', 'example-blog'], $answer['shortcodes']);
        $this->assertNull($answer['facts']);
    }

    public function testBeforeAnyScanTheCommitIsNull(): void
    {
        $answer = NextStepAnswer::of($this->found('mg'), [$this->facts('mg')], null);

        $this->assertNull($answer['steps_commit']);
        $this->assertSame($this->facts('mg'), $answer['facts']);
    }

    public function testItPrintsAsOneJsonObjectWithAnEmptyList(): void
    {
        $json = json_encode(
            NextStepAnswer::of(new SiteFound(null, []), [], null),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $this->assertSame('{"site":null,"shortcodes":[],"facts":null,"steps_commit":null}', $json);
    }
}
