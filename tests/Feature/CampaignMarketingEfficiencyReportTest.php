<?php

namespace Tests\Feature;

use App\Models\Client;
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
use App\Services\CampaignMarketingEfficiencyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignMarketingEfficiencyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_report_shows_complete_paid_click_to_session_diagnostic_and_exports_it(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createMetaReport($user, $target, 60);
        $this->createGoogleAdsReport($user, $target, 40);
        $this->createGoogleAnalyticsReport($user, $target, 180, '18.0000');

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 工作階段／Google Ads點擊')
            ->assertSee('4.50 次／點擊')
            ->assertSee('Google Ads點擊 40 · GA4 工作階段 180')
            ->assertSee('每次參與工作階段成本')
            ->assertSee('0.5556 TWD')
            ->assertSee('每 100 次工作階段重要事件數')
            ->assertSee('10.00 次')
            ->assertSee('不代表轉換率');

        $analysis = $this->get(route('social-engagement-targets.report', $target))->viewData('adSpendAnalysis');
        $this->assertSame(10.0, $analysis['ga4_key_events_per_100_sessions']);
        $this->assertSame('available', $analysis['ga4_key_events_per_100_sessions_status']);
        $this->assertSame(0.5556, $analysis['cost_per_engaged_session']);
        $this->assertSame('available', $analysis['cost_per_engaged_session_status']);

        $csvRows = $this->csvRows(
            $this->actingAs($user)
                ->get(route('social-engagement-targets.report.export', $target))
                ->assertOk()
                ->streamedContent(),
        );
        $csv = array_combine($csvRows[0], $csvRows[1]);

        $this->assertSame('40', $csv['paid_ad_clicks']);
        $this->assertSame('4.5000', $csv['ga4_sessions_per_paid_click']);
        $this->assertSame('google_ads', $csv['paid_click_source']);
        $this->assertSame('complete', $csv['paid_click_session_status']);
        $this->assertSame('10.0000', $csv['ga4_key_events_per_100_sessions']);
        $this->assertSame('available', $csv['ga4_key_events_per_100_sessions_status']);
        $this->assertSame('0.5556', $csv['cost_per_engaged_session']);
        $this->assertSame('available', $csv['cost_per_engaged_session_status']);
    }

    public function test_campaign_report_does_not_sum_clicks_when_a_configured_source_is_missing(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createMetaReport($user, $target, 60);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('缺少已設定廣告來源的點擊資料')
            ->assertSee('Google Ads點擊 —');

        $diagnostic = $response->viewData('adSpendAnalysis');

        $this->assertNull($diagnostic['paid_ad_clicks']);
        $this->assertNull($diagnostic['ga4_sessions_per_paid_click']);
        $this->assertSame('missing_paid_clicks', $diagnostic['paid_click_session_status']);
    }

    public function test_campaign_report_marks_zero_clicks_without_dividing_by_zero(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(metaCampaignId: 'meta-zero-clicks', googleAdsCampaignId: null);
        $this->createMetaReport($user, $target, 0);
        $this->createGoogleAnalyticsReport($user, $target, 0);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('零廣告點擊，無法計算')
            ->assertSee('Meta Ads點擊 0 · GA4 工作階段 0');

        $diagnostic = $response->viewData('adSpendAnalysis');

        $this->assertSame(0, $diagnostic['paid_ad_clicks']);
        $this->assertNull($diagnostic['ga4_sessions_per_paid_click']);
        $this->assertSame('zero_clicks', $diagnostic['paid_click_session_status']);
    }

    public function test_campaign_report_calculates_cost_from_fresh_meta_spend(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(googleAdsCampaignId: null);
        $this->createMetaReport($user, $target, 40);
        $analyticsReport = $this->createGoogleAnalyticsReport($user, $target, 100);
        $analyticsReport->forceFill(['engaged_sessions' => 20])->save();

        $analysis = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->viewData('adSpendAnalysis');

        $this->assertSame('meta_marketing', $analysis['roas_spend_source']);
        $this->assertSame(5.0, $analysis['cost_per_engaged_session']);
        $this->assertSame('available', $analysis['cost_per_engaged_session_status']);
    }

    public function test_campaign_report_calculates_cost_from_fresh_manual_spend(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(
            metaCampaignId: null,
            googleAdsCampaignId: null,
            additionalAttributes: ['ad_spend_amount' => '250.0000', 'ad_spend_currency' => 'TWD'],
        );
        $analyticsReport = $this->createGoogleAnalyticsReport($user, $target, 100);
        $analyticsReport->forceFill(['engaged_sessions' => 50])->save();

        $analysis = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->viewData('adSpendAnalysis');

        $this->assertSame('manual', $analysis['roas_spend_source']);
        $this->assertSame(5.0, $analysis['cost_per_engaged_session']);
        $this->assertSame('available', $analysis['cost_per_engaged_session_status']);
    }

    public function test_campaign_report_suppresses_cost_when_selected_spend_is_stale(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $googleAdsReport = $this->createGoogleAdsReport($user, $target, 40);
        $googleAdsReport->forceFill(['synced_at' => now()->subDays(2)])->save();
        $this->createGoogleAnalyticsReport($user, $target, 100);

        $analysis = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('花費資料已過期')
            ->viewData('adSpendAnalysis');

        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('stale_spend', $analysis['cost_per_engaged_session_status']);
    }

    public function test_campaign_report_keeps_click_count_when_ga4_data_is_missing(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(metaCampaignId: 'meta-without-ga4', googleAdsCampaignId: null);
        $this->createMetaReport($user, $target, 23);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('缺少 GA4 工作階段資料')
            ->assertSee('Meta Ads點擊 23 · GA4 工作階段 —');

        $diagnostic = $response->viewData('adSpendAnalysis');

        $this->assertSame(23, $diagnostic['paid_ad_clicks']);
        $this->assertNull($diagnostic['ga4_sessions_per_paid_click']);
        $this->assertSame('missing_ga4', $diagnostic['paid_click_session_status']);
        $this->assertNull($diagnostic['ga4_key_events_per_100_sessions']);
        $this->assertSame('missing_report', $diagnostic['ga4_key_events_per_100_sessions_status']);
    }

    public function test_campaign_report_suppresses_key_event_rate_for_stale_ga4_data(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createGoogleAnalyticsReport($user, $target, 30, '6.0000');
        $this->createGoogleAdsReport($user, $target, 40);
        $analyticsReport = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $analyticsReport->synced_at = now()->subDays(2);
        $analyticsReport->save();

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 數據已過期');
        $analysis = $response->viewData('adSpendAnalysis');

        $this->assertNull($analysis['ga4_key_events_per_100_sessions']);
        $this->assertSame('stale', $analysis['ga4_key_events_per_100_sessions_status']);
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('stale_ga4', $analysis['cost_per_engaged_session_status']);

        $csvRows = $this->csvRows(
            $this->get(route('social-engagement-targets.report.export', $target))
                ->assertOk()
                ->streamedContent(),
        );
        $csv = array_combine($csvRows[0], $csvRows[1]);

        $this->assertSame('', $csv['ga4_key_events_per_100_sessions']);
        $this->assertSame('stale', $csv['ga4_key_events_per_100_sessions_status']);
        $this->assertSame('', $csv['cost_per_engaged_session']);
        $this->assertSame('stale_ga4', $csv['cost_per_engaged_session_status']);
    }

    public function test_cost_per_engaged_session_reports_unavailable_statuses_and_accepts_zero_spend(): void
    {
        $target = $this->createTarget(
            metaCampaignId: null,
            googleAdsCampaignId: null,
            additionalAttributes: ['ad_spend_amount' => '100.0000', 'ad_spend_currency' => 'TWD'],
        );
        $analyticsReport = new SocialEngagementTargetGoogleAnalyticsReport;
        $analyticsReport->forceFill(['engaged_sessions' => 25, 'currency_code' => 'TWD']);
        $service = app(CampaignMarketingEfficiencyReport::class);

        $analysis = $service->build(
            $target,
            $analyticsReport,
            null,
            roasSpendSnapshotIsFresh: true,
        );
        $this->assertSame(4.0, $analysis['cost_per_engaged_session']);
        $this->assertSame('available', $analysis['cost_per_engaged_session_status']);

        $analysis = $service->build(
            $target,
            $analyticsReport,
            null,
            roasSpendSnapshotIsFresh: false,
        );
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('stale_spend', $analysis['cost_per_engaged_session_status']);

        $analysis = $service->build($target, null, null, roasSpendSnapshotIsFresh: true);
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('missing_ga4', $analysis['cost_per_engaged_session_status']);

        $analysis = $service->build(
            $target,
            $analyticsReport,
            null,
            googleAnalyticsReportIsStale: true,
            roasSpendSnapshotIsFresh: true,
        );
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('stale_ga4', $analysis['cost_per_engaged_session_status']);

        $target->forceFill(['ad_spend_amount' => '0.0000']);
        $analysis = $service->build(
            $target,
            $analyticsReport,
            null,
            roasSpendSnapshotIsFresh: true,
        );
        $this->assertSame(0.0, $analysis['cost_per_engaged_session']);
        $this->assertSame('available', $analysis['cost_per_engaged_session_status']);
    }

    public function test_cost_per_engaged_session_rejects_mismatched_or_invalid_values(): void
    {
        $target = $this->createTarget(
            metaCampaignId: null,
            googleAdsCampaignId: null,
            additionalAttributes: ['ad_spend_amount' => '100.0000', 'ad_spend_currency' => 'TWD'],
        );
        $analyticsReport = new SocialEngagementTargetGoogleAnalyticsReport;
        $analyticsReport->forceFill(['engaged_sessions' => 25, 'currency_code' => 'USD']);
        $service = app(CampaignMarketingEfficiencyReport::class);
        $build = fn (): array => $service->build(
            $target,
            $analyticsReport,
            null,
            roasSpendSnapshotIsFresh: true,
        );

        $analysis = $build();
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('currency_mismatch', $analysis['cost_per_engaged_session_status']);

        $analyticsReport->forceFill(['currency_code' => null, 'engaged_sessions' => null]);
        $analysis = $build();
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('missing_ga4_currency', $analysis['cost_per_engaged_session_status']);

        $analyticsReport->forceFill(['currency_code' => 'TWD', 'engaged_sessions' => null]);
        $analysis = $build();
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('missing_engaged_sessions', $analysis['cost_per_engaged_session_status']);

        $analyticsReport->forceFill(['engaged_sessions' => 0]);
        $analysis = $build();
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('zero_engaged_sessions', $analysis['cost_per_engaged_session_status']);

        $analyticsReport->forceFill(['engaged_sessions' => -1]);
        $analysis = $build();
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('invalid_engaged_sessions', $analysis['cost_per_engaged_session_status']);

        $analyticsReport->forceFill(['engaged_sessions' => 25]);
        $target->forceFill(['ad_spend_amount' => '-1.0000']);
        $analysis = $build();
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('invalid_spend', $analysis['cost_per_engaged_session_status']);

        $target->forceFill(['ad_spend_amount' => null]);
        $analysis = $build();
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('missing_spend', $analysis['cost_per_engaged_session_status']);

        $target->forceFill(['ad_spend_amount' => '100.0000', 'ad_spend_currency' => null]);
        $analysis = $build();
        $this->assertNull($analysis['cost_per_engaged_session']);
        $this->assertSame('missing_spend_currency', $analysis['cost_per_engaged_session_status']);
    }

    public function test_campaign_key_event_rate_handles_missing_or_invalid_source_values(): void
    {
        $target = $this->createTarget();
        $analyticsReport = new SocialEngagementTargetGoogleAnalyticsReport;
        $analyticsReport->forceFill(['sessions' => 10, 'key_events' => null]);

        $analysis = app(CampaignMarketingEfficiencyReport::class)->build($target, $analyticsReport, null);
        $this->assertNull($analysis['ga4_key_events_per_100_sessions']);
        $this->assertSame('missing_key_events', $analysis['ga4_key_events_per_100_sessions_status']);

        $analyticsReport->forceFill(['sessions' => 10, 'key_events' => '-1.0000']);
        $analysis = app(CampaignMarketingEfficiencyReport::class)->build($target, $analyticsReport, null);
        $this->assertNull($analysis['ga4_key_events_per_100_sessions']);
        $this->assertSame('invalid_key_events', $analysis['ga4_key_events_per_100_sessions_status']);

        $analyticsReport->forceFill(['sessions' => null, 'key_events' => '1.0000']);
        $analysis = app(CampaignMarketingEfficiencyReport::class)->build($target, $analyticsReport, null);
        $this->assertNull($analysis['ga4_key_events_per_100_sessions']);
        $this->assertSame('missing_sessions', $analysis['ga4_key_events_per_100_sessions_status']);
    }

    public function test_campaign_report_does_not_require_paid_campaign_ids_for_manual_spend(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(
            metaCampaignId: null,
            googleAdsCampaignId: null,
            additionalAttributes: ['ad_spend_amount' => '250.0000', 'ad_spend_currency' => 'TWD'],
        );

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('尚未設定廣告活動');

        $diagnostic = $response->viewData('adSpendAnalysis');

        $this->assertNull($diagnostic['paid_ad_clicks']);
        $this->assertNull($diagnostic['ga4_sessions_per_paid_click']);
        $this->assertSame('not_configured', $diagnostic['paid_click_session_status']);
    }

    /** @param array<string, mixed> $additionalAttributes */
    private function createTarget(
        ?string $metaCampaignId = 'meta-campaign-123',
        ?string $googleAdsCampaignId = 'google-campaign-123',
        array $additionalAttributes = [],
    ): SocialEngagementTarget {
        return SocialEngagementTarget::factory()->create([
            'tag' => 'Click diagnostic campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 500,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => $metaCampaignId,
            'google_ads_campaign_id' => $googleAdsCampaignId,
            ...$additionalAttributes,
        ]);
    }

    private function createMetaReport(User $user, SocialEngagementTarget $target, int $clicks): SocialEngagementTargetMetaMarketingReport
    {
        $connection = MetaMarketingConnection::factory()->create([
            'user_id' => $user->getKey(),
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Efficiency Meta account',
            'currency_code' => 'TWD',
        ]);

        if ($target->user_id === null) {
            $target->forceFill([
                'user_id' => $user->id,
                'client_id' => Client::ensureDefaultFor($user)->id,
            ])->save();
        }

        MetaMarketingClientAccount::withoutGlobalScopes()->updateOrCreate([
            'meta_marketing_connection_id' => $connection->getKey(),
            'client_id' => $target->client_id,
        ], [
            'ad_account_id' => $connection->ad_account_id,
            'ad_account_name' => $connection->ad_account_name,
            'currency_code' => $connection->currency_code,
        ]);

        return SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Meta campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 700,
            'clicks' => $clicks,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
    }

    private function createGoogleAdsReport(User $user, SocialEngagementTarget $target, int $clicks): SocialEngagementTargetGoogleAdsReport
    {
        $connection = GoogleAdsConnection::factory()->create([
            'user_id' => $user->getKey(),
            'customer_id' => '1234567890',
            'currency_code' => 'TWD',
        ]);

        return SocialEngagementTargetGoogleAdsReport::factory()->create([
            'google_ads_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'customer_id' => $connection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'clicks' => $clicks,
            'currency_code' => 'TWD',
        ]);
    }

    private function createGoogleAnalyticsReport(
        User $user,
        SocialEngagementTarget $target,
        int $sessions,
        string $keyEvents = '0.0000',
    ): SocialEngagementTargetGoogleAnalyticsReport {
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'test-refresh-token',
        ]);
        if ($target->user_id === null) {
            $target->forceFill([
                'user_id' => $user->id,
                'client_id' => Client::ensureDefaultFor($user)->id,
            ])->save();
        }

        GoogleAnalyticsClientProperty::withoutGlobalScopes()->updateOrCreate([
            'google_analytics_connection_id' => $connection->getKey(),
            'client_id' => $target->client_id,
        ], ['property_id' => $connection->property_id]);
        $usesGoogleAdsAutoTagging = $target->googleAnalyticsAttributionMode() === 'google_ads_auto_tagging';

        return SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'property_id' => $connection->property_id,
            'social_engagement_target_id' => $target->getKey(),
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => $target->googleAnalyticsAttributionMode(),
            'campaign_id' => $usesGoogleAdsAutoTagging ? $target->google_ads_campaign_id : null,
            'source_name' => null,
            'medium_name' => null,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => $sessions,
            'engaged_sessions' => $sessions,
            'key_events' => $keyEvents,
            'total_revenue' => '0.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
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
