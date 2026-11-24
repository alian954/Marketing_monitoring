<?php

namespace Tests\Unit;

use App\Services\ClientPortfolioMetricComparison;
use PHPUnit\Framework\TestCase;

class ClientPortfolioMetricComparisonTest extends TestCase
{
    public function test_it_compares_matching_currencies_and_does_not_invent_missing_or_zero_baseline_rates(): void
    {
        $comparison = (new ClientPortfolioMetricComparison)->build(
            [
                [
                    'currency_code' => 'TWD',
                    'ga4_revenue' => 150.0,
                    'ga4_campaign_count' => 1,
                    'meta_spend' => 10.0,
                    'meta_campaign_count' => 1,
                    'google_ads_spend' => 40.0,
                    'google_ads_campaign_count' => 1,
                ],
                [
                    'currency_code' => 'USD',
                    'ga4_revenue' => 20.0,
                    'ga4_campaign_count' => 1,
                    'meta_spend' => 0.0,
                    'meta_campaign_count' => 1,
                    'google_ads_spend' => 0.0,
                    'google_ads_campaign_count' => 0,
                ],
            ],
            [
                [
                    'currency_code' => 'TWD',
                    'ga4_revenue' => 100.0,
                    'ga4_campaign_count' => 1,
                    'meta_spend' => 0.0,
                    'meta_campaign_count' => 1,
                    'google_ads_spend' => 20.0,
                    'google_ads_campaign_count' => 1,
                ],
                [
                    'currency_code' => 'EUR',
                    'ga4_revenue' => 20.0,
                    'ga4_campaign_count' => 1,
                    'meta_spend' => 0.0,
                    'meta_campaign_count' => 1,
                    'google_ads_spend' => 0.0,
                    'google_ads_campaign_count' => 0,
                ],
            ],
            [
                ['source' => 'google_ads', 'source_label' => 'Google Ads', 'currency_code' => 'USD', 'roas' => 4.0],
                ['source' => 'meta_marketing', 'source_label' => 'Meta Ads', 'currency_code' => 'USD', 'roas' => 0.0],
            ],
            [
                ['source' => 'google_ads', 'source_label' => 'Google Ads', 'currency_code' => 'USD', 'roas' => 2.0],
                ['source' => 'meta_marketing', 'source_label' => 'Meta Ads', 'currency_code' => 'TWD', 'roas' => 1.0],
            ],
        );

        $this->assertSame([
            'delta' => 50.0,
            'percent_change' => 50.0,
            'status' => 'available',
        ], $comparison['money']['ga4_revenue']['TWD']);
        $this->assertSame('insufficient', $comparison['money']['ga4_revenue']['USD']['status']);
        $this->assertSame([
            'delta' => 10.0,
            'percent_change' => null,
            'status' => 'zero_baseline',
        ], $comparison['money']['meta_spend']['TWD']);
        $this->assertSame([
            'delta' => 2.0,
            'percent_change' => 100.0,
            'status' => 'available',
        ], [
            'delta' => $comparison['roas']['google_ads|USD']['delta'],
            'percent_change' => $comparison['roas']['google_ads|USD']['percent_change'],
            'status' => $comparison['roas']['google_ads|USD']['status'],
        ]);
        $this->assertSame('insufficient', $comparison['roas']['meta_marketing|USD']['status']);
    }

    public function test_it_marks_zero_previous_roas_as_a_delta_without_a_percentage(): void
    {
        $comparison = (new ClientPortfolioMetricComparison)->build(
            [],
            [],
            [['source' => 'meta_marketing', 'source_label' => 'Meta Ads', 'currency_code' => 'TWD', 'roas' => 3.0]],
            [['source' => 'meta_marketing', 'source_label' => 'Meta Ads', 'currency_code' => 'TWD', 'roas' => 0.0]],
        );

        $this->assertSame([
            'source' => 'meta_marketing',
            'source_label' => 'Meta Ads',
            'currency_code' => 'TWD',
            'delta' => 3.0,
            'percent_change' => null,
            'status' => 'zero_baseline',
        ], $comparison['roas']['meta_marketing|TWD']);
    }
}
