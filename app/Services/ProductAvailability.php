<?php

namespace App\Services;

use App\Models\Product;

/**
 * Resolves a product's effective lifecycle from its code manifest and the operator-managed catalog.
 *
 * The stricter state wins: retired over maintenance over active. Maintenance pauses user-facing entry points
 * but keeps background work running so monitoring and data sync do not leave gaps; retired stops both.
 */
class ProductAvailability
{
    private const LIFECYCLE_SEVERITY = ['active' => 0, 'maintenance' => 1, 'retired' => 2];

    public function __construct(private readonly ProductRegistry $productRegistry) {}

    public function lifecycle(string $productCode): string
    {
        $manifest = $this->productRegistry->find($productCode);

        if ($manifest === null) {
            return 'retired';
        }

        $catalogLifecycle = Product::query()->where('product_code', $productCode)->value('lifecycle');
        $lifecycles = array_filter(
            [$manifest['lifecycle'], $catalogLifecycle],
            static fn (mixed $lifecycle): bool => is_string($lifecycle) && isset(self::LIFECYCLE_SEVERITY[$lifecycle]),
        );

        usort($lifecycles, static fn (string $left, string $right): int => self::LIFECYCLE_SEVERITY[$right] <=> self::LIFECYCLE_SEVERITY[$left]);

        return $lifecycles[0] ?? 'retired';
    }

    /**
     * Central maintenance commands that no product owns always run.
     */
    public function allowsScheduledCommand(string $commandName): bool
    {
        $productCode = $this->productRegistry->productCodeForScheduledCommand($commandName);

        return $productCode === null || $this->lifecycle($productCode) !== 'retired';
    }
}
