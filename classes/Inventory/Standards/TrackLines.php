<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * One track of a site's steps as the standards page draws it: the finished
 * steps folded behind one line, the open ones in full.
 *
 * A site that has been on the page a while has far more finished steps than
 * open ones, and the finished ones were pushing what is left off the screen.
 */
final readonly class TrackLines
{
    /**
     * @param list<StepLine> $done finished steps, in the order given
     * @param list<StepLine> $open steps still to do, in the order given
     */
    private function __construct(
        public string $name,
        public array $done,
        public array $open,
    ) {
    }

    /**
     * @param list<StepLine> $lines a site's steps in display order, each
     *     track's steps together
     * @return list<self> one per track, in the order the tracks first appear
     */
    public static function group(array $lines): array
    {
        $done = [];
        $open = [];
        foreach ($lines as $line) {
            $done[$line->trackName] ??= [];
            $open[$line->trackName] ??= [];
            if ($line->done) {
                $done[$line->trackName][] = $line;
            } else {
                $open[$line->trackName][] = $line;
            }
        }

        $tracks = [];
        foreach ($done as $name => $finished) {
            $tracks[] = new self((string) $name, $finished, $open[$name]);
        }
        return $tracks;
    }

    /**
     * What the folded line says: "6 done" beside open steps, "all 6 done"
     * when nothing is left in the track.
     */
    public function doneSummary(): string
    {
        $count = count($this->done);
        return ($this->open === [] && $count > 1 ? 'all ' : '') . $count . ' done';
    }
}
