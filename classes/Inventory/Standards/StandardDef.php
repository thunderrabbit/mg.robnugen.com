<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The tracks for one kind of site.
 */
final readonly class StandardDef
{
    /**
     * @param list<TrackDef> $tracks in position order
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $tracks,
    ) {
    }
}
