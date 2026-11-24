<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAdsClientAccount;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Services\CampaignEstimatedProfitabilityReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CampaignEstimatedProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_profitability_estimate_is_not_configured_without_a_gross_margin_assumption(): void
    {
        $estimate = $this->buildEstimate(null);

        $this->assertSame('not_configured', $estimate['status']);
        $this->assertNull($estimate['gross_profit_amount']);
        $this->assertNull($estimate['media_contribution_amount']);
        $this->assertNull($estimate['break_even_roas']);
    }

    #[DataProvider('contributionScenarios')]
    public function test_profitability_estimate_calculates_gross_profit_and_contribution(
        string $marginPercent,
        string $revenue,
        string $spend,
        float $expectedGrossProfit,
        float $expectedContribution,
    ): void {
        $estimate = $this->buildEstimate($marginPercent, $revenue, $spend);

        $this->assertSame('available', $estimate['status']);
        $this->assertSame($expectedGrossProfit, $estimate['gross_profit_amount']);
        $this->assertSame($expectedContribution, $estimate['media_contribution_amount']);
        $this->assertSame('TWD', $estimate['currency_code']);
    }

    /** @return array<string, array{string, string, string, float, float}> */
    public static function contributionScenarios(): array
    {
        return [
            'positive media contribution' => ['40.0000', '1000.0000', '200.0000', 400.0, 200.0],
            'zero media contribution' => ['25.0000', '400.0000', '100.0000', 100.0, 0.0],
            'negative media contribution' => ['20.0000', '400.0000', '100.0000', 80.0, -20.0],
        ];
    }

    public function test_break_even_roas_is_the_inverse_of_margin_and_zero_margin_does_not_divide_by_zero(): void
    {
        $positiveMarginEstimate = $this->buildEstimate('40.0000', '1000.0000', '200.0000');
        $zeroMarginEstimate = $this->buildEstimate('0.0000', '1000.0000', '200.0000');

        $this->assertSame(2.5, $positiveMarginEstimate['break_even_roas']);
        $this->assertSame('available', $positiveMarginEstimate['break_even_roas_status']);
        $this->assertSame(0.0, $zeroMarginEstimate['gross_profit_amount']);
        $this->assertSame(-200.0, $zeroMarginEstimate['media_contribution_amount']);
        $this->assertNull($zeroMarginEstimate['break_even_roas']);
        $this->assertSame('zero_margin', $zeroMarginEstimate['break_even_roas_status']);
    }

    public function test_zero_spend_keeps_valid_profitability_amounts_and_break_even_roas(): void
    {
        $estimate = $this->buildEstimate('20.0000', '1000.0000', '0.0000');

        $this->assertSame('available', $estimate['status']);
        $this->assertSame(200.0, $estimate['gross_profit_amount']);
        $this->assertSame(200.0, $estimate['media_contribution_amount']);
        $this->assertSame(5.0, $estimate['break_even_roas']);
    }

    public function test_period_summary_uses_the_selected_period_revenue_and_platform_spend(): void
    {
        $target = SocialEngagementTarget::factory()->make([
            'gross_margin_percent' => '40.0000',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => 'meta-123',
        ]);
        $marketingSummary = [
            'google_analytics' => [
                'status' => 'fresh',
                'revenue' => '200.0000',
                'currency_code' => 'TWD',
            ],
            'meta_marketing' => [
                'status' => 'fresh',
                'spend_amount' => '50.0000',
                'currency_code' => 'TWD',
            ],
            'efficiency' => [
                'ad_spend_amount' => '50.0000',
                'ad_spend_currency' => 'TWD',
                'ad_spend_source' => 'meta_marketing',
                'platform_currency_mismatch' => false,
            ],
        ];

        $estimate = app(CampaignEstimatedProfitabilityReport::class)->buildForMarketingSummary(
            $target,
            $marketingSummary,
            ['from' => '2026-11-02', 'to' => '2026-11-03'],
        );

        $this->assertSame('available', $estimate['status']);
        $this->assertSame(80.0, $estimate['gross_profit_amount']);
        $this->assertSame(30.0, $estimate['media_contribution_amount']);
    }

    public function test_manual_spend_is_unavailable_when_the_report_covers_only_part_of_the_campaign(): void
    {
        $target = SocialEngagementTarget::factory()->make([
            'gross_margin_percent' => '40.0000',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ad_spend_amount' => '50.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $marketingSummary = [
            'google_analytics' => [
                'status' => 'fresh',
                'revenue' => '200.0000',
                'currency_code' => 'TWD',
            ],
            'efficiency' => [
                'ad_spend_source' => 'manual',
                'platform_currency_mismatch' => false,
            ],
        ];

        $estimate = app(CampaignEstimatedProfitabilityReport::class)->buildForMarketingSummary(
            $target,
            $marketingSummary,
            ['from' => '2026-11-02', 'to' => '2026-11-03'],
        );

        $this->assertSame('missing_spend', $estimate['status']);
        $this->assertNull($estimate['media_contribution_amount']);

        $target->meta_campaign_id = 'meta-123';
        $manualFallbackEstimate = app(CampaignEstimatedProfitabilityReport::class)->buildForMarketingSummary(
            $target,
            $marketingSummary,
            ['from' => '2026-11-01', 'to' => '2026-11-30'],
        );

        $this->assertSame('stale', $manualFallbackEstimate['status']);
        $this->assertNull($manualFallbackEstimate['media_contribution_amount']);
    }

    public function test_summary_profitability_combines_fresh_paid_platform_spend_and_rejects_currency_mismatch(): void
    {
        $target = SocialEngagementTarget::factory()->make([
            'gross_margin_percent' => '40.0000',
            'meta_campaign_id' => 'meta-123',
            'google_ads_campaign_id' => 'ads-456',
        ]);
        $marketingSummary = [
            'google_analytics' => [
                'status' => 'fresh',
                'revenue' => '1000.0000',
                'currency_code' => 'TWD',
            ],
            'meta_marketing' => [
                'status' => 'fresh',
                'spend_amount' => '150.0000',
                'currency_code' => 'TWD',
            ],
            'google_ads' => [
                'status' => 'fresh',
                'spend_amount' => '50.0000',
                'currency_code' => 'TWD',
            ],
            'efficiency' => [
                'ad_spend_source' => 'combined_paid_ads',
                'platform_currency_mismatch' => false,
            ],
        ];

        $report = app(CampaignEstimatedProfitabilityReport::class);
        $combinedEstimate = $report->buildForMarketingSummary($target, $marketingSummary);
        $this->assertSame(200.0, $combinedEstimate['media_contribution_amount']);

        $marketingSummary['google_ads']['currency_code'] = 'USD';
        $mismatchedEstimate = $report->buildForMarketingSummary($target, $marketingSummary);
        $this->assertSame('currency_mismatch', $mismatchedEstimate['status']);
        $this->assertNull($mismatchedEstimate['media_contribution_amount']);
    }

    public function test_combined_meta_and_google_ads_spend_is_used_for_contribution(): void
    {
        $estimate = $this->buildEstimate(
            '40.0000',
            '1000.0000',
            '300.0000',
            spendOverrides: ['ad_spend_source' => 'combined_paid_ads'],
        );

        $this->assertSame(400.0, $estimate['gross_profit_amount']);
        $this->assertSame(100.0, $estimate['media_contribution_amount']);
        $this->assertSame(2.5, $estimate['break_even_roas']);
    }

    #[DataProvider('unavailableScenarios')]
    public function test_profitability_values_are_blank_when_required_data_is_missing_stale_or_incomparable(
        ?array $reportOverrides,
        array $spendOverrides,
        bool $googleAnalyticsFresh,
        bool $spendFresh,
        string $expectedStatus,
    ): void {
        $estimate = $this->buildEstimate(
            '40.0000',
            reportOverrides: $reportOverrides,
            spendOverrides: $spendOverrides,
            googleAnalyticsFresh: $googleAnalyticsFresh,
            spendFresh: $spendFresh,
        );

        $this->assertSame($expectedStatus, $estimate['status']);
        $this->assertNull($estimate['gross_profit_amount']);
        $this->assertNull($estimate['media_contribution_amount']);
        $this->assertNull($estimate['break_even_roas']);
    }

    /** @return array<string, array{array<string, mixed>|null, array<string, mixed>, bool, bool, string}> */
    public static function unavailableScenarios(): array
    {
        return [
            'missing GA4 report' => [null, [], true, true, 'missing_ga4'],
            'missing GA4 revenue' => [['total_revenue' => null], [], true, true, 'missing_revenue'],
            'non-finite GA4 revenue' => [['total_revenue' => '1e309'], [], true, true, 'missing_revenue'],
            'gross profit calculation overflow' => [['total_revenue' => '1e308'], [], true, true, 'invalid_calculation'],
            'stale GA4 report' => [[], [], false, true, 'stale'],
            'missing spend' => [[], ['ad_spend_amount' => null], true, true, 'missing_spend'],
            'negative spend' => [[], ['ad_spend_amount' => '-1.0000'], true, true, 'missing_spend'],
            'stale spend' => [[], [], true, false, 'stale'],
            'stale manual spend' => [[], ['ad_spend_source' => 'manual'], true, false, 'stale'],
            'unknown spend currency' => [[], ['ad_spend_currency' => null], true, true, 'currency_unknown'],
            'unknown GA4 currency' => [['currency_code' => null], [], true, true, 'currency_unknown'],
            'currency mismatch' => [[], ['ad_spend_currency' => 'USD'], true, true, 'currency_mismatch'],
            'paid platforms currency mismatch' => [[], ['ad_spend_source' => 'currency_mismatch', 'platform_currency_mismatch' => true], true, true, 'currency_mismatch'],
        ];
    }

    public function test_campaign_report_displays_and_exports_fresh_profitability_estimates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Profitability campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '123456789',
            'google_ads_campaign_id' => null,
            'gross_margin_percent' => '40.0000',
        ]);
        $client = Client::ensureDefaultFor($user);
        $target->forceFill([
            'user_id' => $user->workspaceOwnerId(),
            'client_id' => $client->getKey(),
        ])->save();
        $analyticsConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'test-refresh-token',
        ]);
        GoogleAnalyticsClientProperty::query()->create([
            'google_analytics_connection_id' => $analyticsConnection->getKey(),
            'client_id' => $client->getKey(),
            'property_id' => $analyticsConnection->property_id,
        ]);
        $metaConnection = MetaMarketingConnection::factory()->create([
            'user_id' => $user->getKey(),
            'ad_account_id' => '987654321',
            'currency_code' => 'TWD',
        ]);
        MetaMarketingClientAccount::query()->create([
            'meta_marketing_connection_id' => $metaConnection->getKey(),
            'client_id' => $client->getKey(),
            'ad_account_id' => $metaConnection->ad_account_id,
            'ad_account_name' => $metaConnection->ad_account_name,
            'currency_code' => $metaConnection->currency_code,
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $analyticsConnection->getKey(),
            'property_id' => $analyticsConnection->property_id,
            'social_engagement_target_id' => $target->getKey(),
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => 'manual_utm',
            'campaign_id' => null,
            'source_name' => null,
            'medium_name' => null,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 100,
            'engaged_sessions' => 80,
            'key_events' => '20.0000',
            'total_revenue' => '800.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'ad_account_id' => $metaConnection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Meta campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 80,
            'actions' => [],
            'action_values' => [],
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('估算毛利貢獻')
            ->assertSee('320.00 TWD')
            ->assertSee('220.00 TWD')
            ->assertSee('2.50x')
            ->assertSee('不含物流、交易費與固定營運成本');
        $estimate = $response->viewData('estimatedProfitability');

        $this->assertSame(320.0, $estimate['gross_profit_amount']);
        $this->assertSame(220.0, $estimate['media_contribution_amount']);
        $this->assertSame(2.5, $estimate['break_even_roas']);

        $csvRows = $this->csvRows(
            $this->actingAs($user)
                ->get(route('social-engagement-targets.report.export', $target))
                ->assertOk()
                ->streamedContent(),
        );
        $csv = array_combine($csvRows[0], $csvRows[1]);

        $this->assertSame('40.0000', $csv['estimated_gross_margin_percent']);
        $this->assertSame('320.0000', $csv['estimated_gross_profit_amount']);
        $this->assertSame('220.0000', $csv['estimated_media_contribution_amount']);
        $this->assertSame('2.5000', $csv['break_even_roas']);
        $this->assertSame('available', $csv['profitability_estimate_status']);

        $target->update(['google_ads_campaign_id' => '12345678']);
        $analyticsReport = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $analyticsReport->update([
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => '12345678',
        ]);
        $partialSpendResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $partialEstimate = $partialSpendResponse->viewData('estimatedProfitability');

        $this->assertSame('stale', $partialEstimate['status']);
        $this->assertStringContainsString('缺漏', $partialEstimate['status_label']);
        $this->assertNull($partialEstimate['gross_profit_amount']);
        $this->assertNull($partialEstimate['media_contribution_amount']);
        $this->assertNull($partialEstimate['break_even_roas']);

        $googleAdsConnection = GoogleAdsConnection::factory()->create([
            'user_id' => $user->getKey(),
            'customer_id' => '1234567890',
            'currency_code' => 'TWD',
        ]);
        GoogleAdsClientAccount::query()->create([
            'google_ads_connection_id' => $googleAdsConnection->getKey(),
            'client_id' => $client->getKey(),
            'customer_id' => $googleAdsConnection->customer_id,
            'login_customer_id' => $googleAdsConnection->login_customer_id,
            'customer_name' => $googleAdsConnection->customer_name,
            'currency_code' => $googleAdsConnection->currency_code,
        ]);
        $googleAdsReport = SocialEngagementTargetGoogleAdsReport::factory()->create([
            'google_ads_connection_id' => $googleAdsConnection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'customer_id' => $googleAdsConnection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '50.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
        $user = User::query()->findOrFail($user->getKey());
        $combinedResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $combinedEstimate = $combinedResponse->viewData('estimatedProfitability');
        $combinedSpend = $combinedResponse->viewData('adSpendAnalysis');

        $this->assertSame('combined_paid_ads', $combinedSpend['ad_spend_source']);
        $this->assertSame('150.0000', $combinedSpend['ad_spend_amount']);
        $this->assertSame('available', $combinedEstimate['status']);
        $this->assertSame(170.0, $combinedEstimate['media_contribution_amount']);

        $googleAdsReport->update(['synced_at' => now()->subDay()]);
        $staleCombinedResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $staleCombinedEstimate = $staleCombinedResponse->viewData('estimatedProfitability');

        $this->assertSame('stale', $staleCombinedEstimate['status']);
        $this->assertNull($staleCombinedEstimate['gross_profit_amount']);
        $this->assertNull($staleCombinedEstimate['media_contribution_amount']);
        $this->assertNull($staleCombinedEstimate['break_even_roas']);
    }

    /**
     * @param  array<string, mixed>|null  $reportOverrides
     * @param  array<string, mixed>  $spendOverrides
     * @return array<string, mixed>
     */
    private function buildEstimate(
        ?string $marginPercent,
        ?string $revenue = '1000.0000',
        string $spend = '200.0000',
        ?array $reportOverrides = [],
        array $spendOverrides = [],
        bool $googleAnalyticsFresh = true,
        bool $spendFresh = true,
    ): array {
        $target = SocialEngagementTarget::factory()->make([
            'gross_margin_percent' => $marginPercent,
        ]);
        $analyticsReport = null;

        if ($reportOverrides !== null) {
            $analyticsReport = new SocialEngagementTargetGoogleAnalyticsReport;
            $analyticsReport->forceFill(array_merge([
                'total_revenue' => $revenue,
                'currency_code' => 'TWD',
            ], $reportOverrides));
        }
        $spendAnalysis = array_merge([
            'ad_spend_amount' => $spend,
            'ad_spend_currency' => 'TWD',
            'ad_spend_source' => 'meta_marketing',
            'platform_currency_mismatch' => false,
        ], $spendOverrides);

        return app(CampaignEstimatedProfitabilityReport::class)->build(
            $target,
            $analyticsReport,
            $spendAnalysis,
            $googleAnalyticsFresh,
            $spendFresh,
        );
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }
}
