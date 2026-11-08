<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * One site's standing and open steps as the v1 API gives them
 * (GET /standards/site). Built from the same SiteReport the standards page
 * shows, so the page and the API cannot disagree about which step is next.
 *
 * Domains, DreamHost users, ssh aliases and server paths stay out. Two free
 * texts can carry them, so neither is passed on: what mg's own host checks
 * say (HostChecks names the domain or user), and why a scan skipped the site
 * (ServerChoice and ssh name the domain, alias or directory). The page, which
 * only its owner sees, still shows both.
 *
 * The PHP the site is served on does go out, as the latest sweep saw it: the
 * php_version track's how-tos say "the PHP the site is served on today", and
 * `php -v` over ssh cannot answer that (it is the shell's PHP). An agent with
 * no sweep to read would otherwise have to guess.
 *
 * @phpstan-type Answer array{shortcode: string, standard: string, standing: array{mark: string, text: string}, php_current: ?string, test_php: ?string, last_checked_utc: ?string, skipped_reason: ?string, open_steps: list<array{slug: string, track: string, title: string, whose_move: string, detail: string, check: string, check_param: ?string, explanation: string, how_to: string}>}
 */
final class SiteAnswer
{
    public const string HOST_DETAIL = "mg's own check; the standards page has the details";
    public const string SKIPPED = 'the latest scan skipped this site; the standards page says why';

    /**
     * @param ?string $phpCurrent the PHP serving the site, as the sweep saw it; null before a sweep
     * @param ?string $testPhp the PHP serving its test domain; null without one, or before a sweep
     * @return Answer
     */
    public static function of(
        string $standard,
        string $shortcode,
        ?string $lastCheckedUtc,
        ?string $skippedReason,
        SiteReport $report,
        ?string $phpCurrent,
        ?string $testPhp,
    ): array {
        $open = [];
        foreach ($report->lines as $line) {
            if ($line->done) {
                continue;
            }
            $open[] = [
                'slug' => $line->slug,
                'track' => $line->trackName,
                'title' => $line->title,
                'whose_move' => $line->whoseMove->value,
                'detail' => in_array($line->checkName, HostChecks::NAMES, true) ? self::HOST_DETAIL : $line->detail,
                'check' => $line->checkName,
                'check_param' => $line->checkParam,
                'explanation' => $line->explanation,
                'how_to' => $line->howTo,
            ];
        }

        return [
            'shortcode' => $shortcode,
            'standard' => $standard,
            'standing' => ['mark' => $report->standing->mark->value, 'text' => $report->standing->summary],
            'php_current' => $phpCurrent,
            'test_php' => $testPhp,
            'last_checked_utc' => $lastCheckedUtc,
            'skipped_reason' => $skippedReason === null ? null : self::SKIPPED,
            'open_steps' => $open,
        ];
    }
}
