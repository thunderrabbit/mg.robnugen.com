<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * One site's standing and open steps as the v1 API gives them
 * (GET /standards/site). Built from the same SiteReport the standards page
 * shows, so the page and the API cannot disagree about which step is next.
 *
 * Domains, DreamHost users, ssh aliases and server paths stay out.
 *
 * @phpstan-type Answer array{shortcode: string, standard: string, standing: array{mark: string, text: string}, last_checked_utc: ?string, skipped_reason: ?string, open_steps: list<array{slug: string, track: string, title: string, whose_move: string, detail: string, check: string, check_param: ?string, explanation: string, how_to: string}>}
 */
final class SiteAnswer
{
    /**
     * @return Answer
     */
    public static function of(
        string $standard,
        string $shortcode,
        ?string $lastCheckedUtc,
        ?string $skippedReason,
        SiteReport $report,
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
                'detail' => $line->detail,
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
            'last_checked_utc' => $lastCheckedUtc,
            'skipped_reason' => $skippedReason,
            'open_steps' => $open,
        ];
    }
}
