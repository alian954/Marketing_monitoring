<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAnalyticsItemPerformanceReport
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{status: string, window_start_on: string|null, window_end_on: string|null, currency_code: string|null, truncated: bool, sort: string, rows: list<array<string, mixed>>}
     */
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'item_revenue'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && $expiresAt !== null && $expiresAt->isPast()) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['item_revenue', 'items_purchased', 'items_added_to_cart', 'items_viewed', 'item_name'], true)
            ? $sort
            : 'item_revenue';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            $itemsViewed = is_array($row) ? filter_var($row['items_viewed'] ?? null, FILTER_VALIDATE_INT) : false;
            $itemsAddedToCart = is_array($row) ? filter_var($row['items_added_to_cart'] ?? null, FILTER_VALIDATE_INT) : false;
            $itemsPurchased = is_array($row) ? filter_var($row['items_purchased'] ?? null, FILTER_VALIDATE_INT) : false;

            if (! is_array($row)
                || ! is_string($row['item_name'] ?? null)
                || $itemsViewed === false || $itemsViewed < 0
                || $itemsAddedToCart === false || $itemsAddedToCart < 0
                || $itemsPurchased === false || $itemsPurchased < 0
                || ! is_numeric($row['item_revenue'] ?? null)
                || ! is_finite((float) $row['item_revenue'])) {
                continue;
            }

            $rows[] = [
                'item_name' => $row['item_name'],
                'items_viewed' => $itemsViewed,
                'items_added_to_cart' => $itemsAddedToCart,
                'items_purchased' => $itemsPurchased,
                'item_revenue' => (string) $row['item_revenue'],
            ];
        }

        usort($rows, static function (array $first, array $second) use ($sort): int {
            $sortOrder = $sort === 'item_name'
                ? strcasecmp($first['item_name'], $second['item_name'])
                : (float) $second[$sort] <=> (float) $first[$sort];

            return $sortOrder !== 0 ? $sortOrder : strcasecmp($first['item_name'], $second['item_name']);
        });

        return [
            'status' => $status,
            'window_start_on' => is_string($snapshot['window_start_on'] ?? null) ? $snapshot['window_start_on'] : null,
            'window_end_on' => is_string($snapshot['window_end_on'] ?? null) ? $snapshot['window_end_on'] : null,
            'currency_code' => is_string($snapshot['currency_code'] ?? null) ? $snapshot['currency_code'] : null,
            'truncated' => ($snapshot['truncated'] ?? false) === true,
            'sort' => $sort,
            'rows' => $status === 'complete' ? $rows : [],
        ];
    }
}
