<?php

namespace App\Services;

class ClientPortfolioMetricComparison
{
    /**
     * @var array<string, array{amount_key: string, count_key: string}>
     */
    private const MONEY_METRICS = [
        'ga4_revenue' => ['amount_key' => 'ga4_revenue', 'count_key' => 'ga4_campaign_count'],
        'meta_spend' => ['amount_key' => 'meta_spend', 'count_key' => 'meta_campaign_count'],
        'google_ads_spend' => ['amount_key' => 'google_ads_spend', 'count_key' => 'google_ads_campaign_count'],
    ];

    /**
     * @param  list<array<string, mixed>>  $currentMoneyGroups
     * @param  list<array<string, mixed>>  $previousMoneyGroups
     * @param  list<array<string, mixed>>  $currentRoasGroups
     * @param  list<array<string, mixed>>  $previousRoasGroups
     * @return array{
     *     money: array<string, array<string, array{delta: float|null, percent_change: float|null, status: 'available'|'zero_baseline'|'insufficient'}>>,
     *     roas: array<string, array{source: string, source_label: string, currency_code: string, delta: float|null, percent_change: float|null, status: 'available'|'zero_baseline'|'insufficient'}>
     * }
     */
    public function build(
        array $currentMoneyGroups,
        array $previousMoneyGroups,
        array $currentRoasGroups,
        array $previousRoasGroups,
    ): array {
        $moneyChanges = [];

        foreach (self::MONEY_METRICS as $metric => $definition) {
            $currentValues = $this->indexMoneyGroups($currentMoneyGroups, $definition['amount_key'], $definition['count_key']);
            $previousValues = $this->indexMoneyGroups($previousMoneyGroups, $definition['amount_key'], $definition['count_key']);
            $currencies = array_unique([...array_keys($currentValues), ...array_keys($previousValues)]);
            $moneyChanges[$metric] = [];

            foreach ($currencies as $currencyCode) {
                $moneyChanges[$metric][$currencyCode] = $this->compareValues(
                    $currentValues[$currencyCode] ?? null,
                    $previousValues[$currencyCode] ?? null,
                );
            }
        }

        $currentRoas = $this->indexRoasGroups($currentRoasGroups);
        $previousRoas = $this->indexRoasGroups($previousRoasGroups);
        $roasChanges = [];
        $roasKeys = array_unique([...array_keys($currentRoas), ...array_keys($previousRoas)]);

        foreach ($roasKeys as $key) {
            $currentGroup = $currentRoas[$key] ?? null;
            $previousGroup = $previousRoas[$key] ?? null;
            $group = $currentGroup ?? $previousGroup;

            if ($group === null) {
                continue;
            }

            $roasChanges[$key] = [
                'source' => $group['source'],
                'source_label' => $group['source_label'],
                'currency_code' => $group['currency_code'],
                ...$this->compareValues($currentGroup['value'] ?? null, $previousGroup['value'] ?? null),
            ];
        }

        return [
            'money' => $moneyChanges,
            'roas' => $roasChanges,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, float>
     */
    private function indexMoneyGroups(array $groups, string $amountKey, string $countKey): array
    {
        $values = [];

        foreach ($groups as $group) {
            $currencyCode = $group['currency_code'] ?? null;
            $amount = $group[$amountKey] ?? null;
            $campaignCount = $group[$countKey] ?? null;

            if (! is_string($currencyCode)
                || $currencyCode === ''
                || ! is_int($campaignCount)
                || $campaignCount < 1
                || ! is_numeric($amount)
                || ! is_finite((float) $amount)) {
                continue;
            }

            $values[$currencyCode] = (float) $amount;
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, array{source: string, source_label: string, currency_code: string, value: float}>
     */
    private function indexRoasGroups(array $groups): array
    {
        $values = [];

        foreach ($groups as $group) {
            $source = $group['source'] ?? null;
            $sourceLabel = $group['source_label'] ?? null;
            $currencyCode = $group['currency_code'] ?? null;
            $roas = $group['roas'] ?? null;

            if (! is_string($source)
                || $source === ''
                || ! is_string($sourceLabel)
                || ! is_string($currencyCode)
                || ! is_numeric($roas)
                || ! is_finite((float) $roas)) {
                continue;
            }

            $key = $source.'|'.$currencyCode;
            $values[$key] = [
                'source' => $source,
                'source_label' => $sourceLabel,
                'currency_code' => $currencyCode,
                'value' => (float) $roas,
            ];
        }

        return $values;
    }

    /**
     * @return array{delta: float|null, percent_change: float|null, status: 'available'|'zero_baseline'|'insufficient'}
     */
    private function compareValues(?float $currentValue, ?float $previousValue): array
    {
        if ($currentValue === null || $previousValue === null) {
            return ['delta' => null, 'percent_change' => null, 'status' => 'insufficient'];
        }

        $delta = $currentValue - $previousValue;

        if (! is_finite($delta)) {
            return ['delta' => null, 'percent_change' => null, 'status' => 'insufficient'];
        }

        if ($previousValue === 0.0) {
            return ['delta' => $delta, 'percent_change' => null, 'status' => 'zero_baseline'];
        }

        return [
            'delta' => $delta,
            'percent_change' => ($delta / abs($previousValue)) * 100,
            'status' => 'available',
        ];
    }
}
