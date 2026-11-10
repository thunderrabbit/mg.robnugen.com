<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Saves the order Rob dragged the board into (SiteOrder::plan). The only
 * writes the standards pages make; every other site fact is site.sh's.
 */
final readonly class SiteOrderStore
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Stores every place in the plan, all or none.
     *
     * @param list<array{shortcode: string, pinned: bool, sort_order: int}> $plan
     */
    public function save(array $plan): void
    {
        $update = $this->pdo->prepare('UPDATE std_sites SET pinned = ?, sort_order = ? WHERE shortcode = ?');
        $this->pdo->beginTransaction();
        try {
            foreach ($plan as $place) {
                $update->execute([$place['pinned'] ? 1 : 0, $place['sort_order'], $place['shortcode']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
