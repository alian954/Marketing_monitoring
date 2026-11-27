<?php

namespace App\Services;

use App\Models\Client;

/**
 * Compares a client's imported store revenue with paid-media spend for the same period.
 *
 * MER (marketing efficiency ratio) = store revenue ÷ total ad spend, which does not depend on any
 * ad platform's attribution model, so it is a useful check on platform-reported ROAS.
 */
class ClientStoreRevenueSummary
{
    /**
     * @param  array{currency_groups: list<array{currency_code: string, total: array<string, mixed>}>, primary_currency_code: string|null}  $overview
     * @return array{
     *     has_data: bool,
     *     currency_code: string|null,
     *     revenue: float,
     *     orders: int,
     *     new_customer_orders: int|null,
     *     ad_spend: float|null,
     *     platform_conversion_value: float|null,
     *     mer: float|null,
     *     new_customer_acquisition_cost: float|null,
     *     platform_to_store_revenue_ratio: float|null,
     *     other_currency_revenue: array<string, float>,
     *     daily_revenue: array<string, float>
     * }
     */
    public function build(Client $client, string $fromOn, string $toOn, array $overview): array
    {
        $rows = $client->storeOrders()
            ->whereDate('ordered_on', '>=', $fromOn)
            ->whereDate('ordered_on', '<=', $toOn)
            ->selectRaw('currency_code, ordered_on, SUM(revenue) as revenue_total, SUM(orders_count) as orders_total, SUM(new_customer_orders) as new_total, COUNT(new_customer_orders) as new_known')
            ->groupBy('currency_code', 'ordered_on')
            ->get();
        $byCurrency = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency_code;
            $date = substr((string) $row->getRawOriginal('ordered_on'), 0, 10);
            $byCurrency[$currency] ??= ['revenue' => 0.0, 'orders' => 0, 'new' => 0, 'new_known' => 0, 'daily' => []];
            $byCurrency[$currency]['revenue'] += (float) $row->revenue_total;
            $byCurrency[$currency]['orders'] += (int) $row->orders_total;
            $byCurrency[$currency]['new'] += (int) $row->new_total;
            $byCurrency[$currency]['new_known'] += (int) $row->new_known;
            $byCurrency[$currency]['daily'][$date] = round(($byCurrency[$currency]['daily'][$date] ?? 0.0) + (float) $row->revenue_total, 2);
        }

        $currencyCode = $overview['primary_currency_code'] ?? null;

        if ($currencyCode === null || ! array_key_exists($currencyCode, $byCurrency)) {
            uasort($byCurrency, static fn (array $first, array $second): int => $second['revenue'] <=> $first['revenue']);
            $currencyCode = array_key_first($byCurrency);
        }

        $selected = $currencyCode === null ? null : $byCurrency[$currencyCode];
        $paidTotal = null;

        foreach ($overview['currency_groups'] ?? [] as $group) {
            if ($group['currency_code'] === $currencyCode) {
                $paidTotal = $group['total'];
            }
        }

        $revenue = round((float) ($selected['revenue'] ?? 0.0), 2);
        $adSpend = $paidTotal === null ? null : (float) $paidTotal['spend'];
        $platformValue = $paidTotal === null ? null : (float) $paidTotal['conversion_value'];
        $newCustomerOrders = $selected !== null && $selected['new_known'] > 0 ? $selected['new'] : null;
        $otherCurrencies = [];

        foreach ($byCurrency as $code => $totals) {
            if ($code !== $currencyCode) {
                $otherCurrencies[$code] = round($totals['revenue'], 2);
            }
        }

        $daily = $selected['daily'] ?? [];
        ksort($daily);

        return [
            'has_data' => $selected !== null,
            'currency_code' => $currencyCode,
            'revenue' => $revenue,
            'orders' => (int) ($selected['orders'] ?? 0),
            'new_customer_orders' => $newCustomerOrders,
            'ad_spend' => $adSpend,
            'platform_conversion_value' => $platformValue,
            'mer' => $adSpend !== null && $adSpend > 0 && $selected !== null ? round($revenue / $adSpend, 2) : null,
            'new_customer_acquisition_cost' => $adSpend !== null && $newCustomerOrders !== null && $newCustomerOrders > 0 ? round($adSpend / $newCustomerOrders, 2) : null,
            'platform_to_store_revenue_ratio' => $platformValue !== null && $revenue > 0 ? round($platformValue / $revenue, 2) : null,
            'other_currency_revenue' => $otherCurrencies,
            'daily_revenue' => $daily,
        ];
    }
}
