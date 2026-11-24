<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAdsConnection;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementPaidMediaTrendTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_report_shows_scoped_paid_media_history_and_comparable_changes(): void
    {
        $this->travelTo('2026-11-22 08:00:00');
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $metaConnection = MetaMarketingConnection::factory()->create([
            'user_id' => $user->id,
            'ad_account_id' => '123456789',
        ]);
        $otherMetaConnection = MetaMarketingConnection::factory()->create(['user_id' => $otherUser->id]);
        $googleAdsConnection = GoogleAdsConnection::factory()->create([
            'user_id' => $user->id,
            'customer_id' => '1234567890',
        ]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Winter campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
            'google_ads_campaign_id' => '987654322',
        ]);
        $client = Client::ensureDefaultFor($user);
        $target->forceFill([
            'user_id' => $user->workspaceOwnerId(),
            'client_id' => $client->getKey(),
        ])->save();
        $googleAdsConnection->clientAccounts()->create([
            'client_id' => $client->getKey(),
            'customer_id' => '1234567890',
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);

        $this->createMetaSnapshot($target, $metaConnection, '2026-11-20 06:00:00', [
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 20,
            'currency_code' => 'TWD',
            'action_values' => [
                ['action_type' => 'purchase', 'value' => '5000.0000'],
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '3000.0000'],
                ['action_type' => 'omni_purchase', 'value' => '900.0000'],
            ],
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-21 06:00:00', [
            'spend_amount' => '150.0000',
            'impressions' => 1600,
            'reach' => 1000,
            'clicks' => 35,
            'currency_code' => 'TWD',
            'action_values' => [
                ['action_type' => 'purchase', 'value' => '2000.0000'],
                ['action_type' => 'omni_purchase', 'value' => '600.0000'],
            ],
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-22 06:00:00', [
            'spend_amount' => '20.0000',
            'impressions' => 2000,
            'reach' => 1200,
            'clicks' => 40,
            'currency_code' => 'USD',
            'action_values' => [
                ['action_type' => 'omni_purchase', 'value' => '20.0000'],
            ],
        ]);
        $this->createMetaSnapshot($target, $otherMetaConnection, '2026-11-22 07:00:00', [
            'spend_amount' => '99999.0000',
            'impressions' => 900000,
            'reach' => 800000,
            'clicks' => 40000,
            'currency_code' => 'TWD',
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-22 07:30:00', [
            'campaign_id' => '987654329',
            'spend_amount' => '88888.0000',
            'currency_code' => 'TWD',
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-22 07:40:00', [
            'account_id' => '111111111',
            'spend_amount' => '77777.0000',
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-22 07:50:00', [
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-31',
            'spend_amount' => '66666.0000',
        ]);
        $this->createGoogleAdsSnapshot($target, $googleAdsConnection, '2026-11-20 06:00:00', [
            'spend_amount' => '50.0000',
            'impressions' => 500,
            'clicks' => 10,
            'conversions' => '1.0000',
            'conversions_value' => '40.0000',
            'currency_code' => 'TWD',
        ]);
        $this->createGoogleAdsSnapshot($target, $googleAdsConnection, '2026-11-22 06:00:00', [
            'spend_amount' => '75.0000',
            'impressions' => 800,
            'clicks' => 22,
            'conversions' => '4.0000',
            'conversions_value' => '100.0000',
            'currency_code' => 'TWD',
        ]);
        $this->createGoogleAdsSnapshot($target, $googleAdsConnection, '2026-11-22 07:40:00', [
            'account_id' => '1111111111',
            'spend_amount' => '77777.0000',
        ]);
        $this->createCurrentReports($target, $metaConnection, $googleAdsConnection);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 歷史讀值')
            ->assertSee('頻率')
            ->assertSee('Google Ads 歷史讀值')
            ->assertSee('Meta Ads 最近 30 次累計成效讀值與前次差異')
            ->assertSee('Google Ads 最近 30 次累計成效讀值與前次差異')
            ->assertSee('<th scope="col">CTR</th>', false)
            ->assertSee('<th scope="col">CPC</th>', false)
            ->assertSee('<th scope="col">CPM</th>', false)
            ->assertSee('<th scope="col">每次轉換成本</th>', false)
            ->assertSee('<th scope="col">購買 ROAS</th>', false)
            ->assertSee('<th scope="col">轉換價值 ROAS</th>', false)
            ->assertSee('Meta 購買 ROAS 以 omni_purchase 轉換價值除以花費')
            ->assertSee(route('social-engagement-targets.paid-media.history', [$target, 'meta-marketing']), false)
            ->assertSee(route('social-engagement-targets.paid-media.history', [$target, 'google-ads']), false)
            ->assertSee('<th scope="col">讀值時間</th>', false)
            ->assertSee('2,000')
            ->assertSee('100.00 TWD');
        $response->assertSee('9.00x')
            ->assertSee('4.00x')
            ->assertSee('1.33x');

        $metaTrend = $response->viewData('metaMarketingTrend');
        $this->assertCount(3, $metaTrend);
        $this->assertSame(50.0, $metaTrend[1]['spend_change']);
        $this->assertSame(600, $metaTrend[1]['impressions_change']);
        $this->assertSame(200, $metaTrend[1]['reach_change']);
        $this->assertSame(1.6, $metaTrend[1]['frequency']);
        $this->assertSame(0.35, $metaTrend[1]['frequency_change']);
        $this->assertSame(15, $metaTrend[1]['clicks_change']);
        $this->assertSame(2.19, $metaTrend[1]['ctr_percent']);
        $this->assertSame(0.19, $metaTrend[1]['ctr_change_percentage_points']);
        $this->assertSame(4.2857, $metaTrend[1]['cost_per_click']);
        $this->assertSame(-0.7143, $metaTrend[1]['cost_per_click_change']);
        $this->assertSame(93.75, $metaTrend[1]['cost_per_thousand_impressions']);
        $this->assertSame(-6.25, $metaTrend[1]['cost_per_thousand_impressions_change']);
        $this->assertSame('600.0000', $metaTrend[1]['roas_value']);
        $this->assertSame(4.0, $metaTrend[1]['roas']);
        $this->assertSame(-5.0, $metaTrend[1]['roas_change']);
        $this->assertSame(1.0, $metaTrend[2]['roas']);
        $this->assertNull($metaTrend[2]['roas_change']);
        $this->assertFalse($metaTrend[2]['currency_comparable']);
        $this->assertNull($metaTrend[2]['spend_change']);
        $this->assertNull($metaTrend[2]['cost_per_click_change']);
        $this->assertSame(400, $metaTrend[2]['impressions_change']);

        $googleAdsTrend = $response->viewData('googleAdsTrend');
        $this->assertCount(2, $googleAdsTrend);
        $this->assertSame(25.0, $googleAdsTrend[1]['spend_change']);
        $this->assertSame(300, $googleAdsTrend[1]['impressions_change']);
        $this->assertNull($googleAdsTrend[1]['frequency']);
        $this->assertSame(12, $googleAdsTrend[1]['clicks_change']);
        $this->assertSame(3.0, $googleAdsTrend[1]['conversions_change']);
        $this->assertSame(60.0, $googleAdsTrend[1]['conversions_value_change']);
        $this->assertSame(2.75, $googleAdsTrend[1]['ctr_percent']);
        $this->assertSame(0.75, $googleAdsTrend[1]['ctr_change_percentage_points']);
        $this->assertSame(3.4091, $googleAdsTrend[1]['cost_per_click']);
        $this->assertSame(-1.5909, $googleAdsTrend[1]['cost_per_click_change']);
        $this->assertSame(93.75, $googleAdsTrend[1]['cost_per_thousand_impressions']);
        $this->assertSame(-6.25, $googleAdsTrend[1]['cost_per_thousand_impressions_change']);
        $this->assertSame(18.75, $googleAdsTrend[1]['cost_per_conversion']);
        $this->assertSame(-31.25, $googleAdsTrend[1]['cost_per_conversion_change']);
        $this->assertSame(18.18, $googleAdsTrend[1]['conversion_rate_percent']);
        $this->assertSame(8.18, $googleAdsTrend[1]['conversion_rate_change_percentage_points']);
        $this->assertSame(1.33, $googleAdsTrend[1]['roas']);
        $this->assertSame(0.53, $googleAdsTrend[1]['roas_change']);
    }

    public function test_paid_media_roas_requires_a_value_and_positive_spend_and_only_compares_matching_currencies(): void
    {
        $user = User::factory()->create();
        $metaConnection = MetaMarketingConnection::factory()->create(['user_id' => $user->id]);
        $googleAdsConnection = GoogleAdsConnection::factory()->create(['user_id' => $user->id]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'ROAS quality campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
            'google_ads_campaign_id' => '987654322',
        ]);

        $this->createMetaSnapshot($target, $metaConnection, '2026-11-20 06:00:00', [
            'spend_amount' => '100.0000',
            'action_values' => [],
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-20 07:00:00', [
            'spend_amount' => '0.0000',
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '300.0000']],
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-20 08:00:00', [
            'spend_amount' => '100.0000',
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '500.0000']],
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-20 09:00:00', [
            'spend_amount' => '100.0000',
            'currency_code' => 'USD',
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '600.0000']],
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-20 10:00:00', [
            'spend_amount' => '100.0000',
            'currency_code' => 'USD',
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '700.0000']],
        ]);

        $this->createGoogleAdsSnapshot($target, $googleAdsConnection, '2026-11-20 06:00:00', [
            'spend_amount' => '100.0000',
            'conversions_value' => null,
        ]);
        $this->createGoogleAdsSnapshot($target, $googleAdsConnection, '2026-11-20 07:00:00', [
            'spend_amount' => '0.0000',
            'conversions_value' => '800.0000',
        ]);
        $this->createGoogleAdsSnapshot($target, $googleAdsConnection, '2026-11-20 08:00:00', [
            'spend_amount' => '100.0000',
            'conversions_value' => '500.0000',
        ]);
        $this->createGoogleAdsSnapshot($target, $googleAdsConnection, '2026-11-20 09:00:00', [
            'spend_amount' => '50.0000',
            'conversions_value' => '250.0000',
        ]);
        $this->createGoogleAdsSnapshot($target, $googleAdsConnection, '2026-11-20 10:00:00', [
            'spend_amount' => '100.0000',
            'conversions_value' => '500.0000',
            'currency_code' => 'USD',
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();

        $metaTrend = $response->viewData('metaMarketingTrend');
        $this->assertNull($metaTrend[0]['roas']);
        $this->assertNull($metaTrend[1]['roas']);
        $this->assertSame(5.0, $metaTrend[2]['roas']);
        $this->assertNull($metaTrend[2]['roas_change']);
        $this->assertSame(6.0, $metaTrend[3]['roas']);
        $this->assertNull($metaTrend[3]['roas_change']);
        $this->assertSame(1.0, $metaTrend[4]['roas_change']);

        $googleAdsTrend = $response->viewData('googleAdsTrend');
        $this->assertNull($googleAdsTrend[0]['roas']);
        $this->assertNull($googleAdsTrend[1]['roas']);
        $this->assertSame(5.0, $googleAdsTrend[2]['roas']);
        $this->assertSame(5.0, $googleAdsTrend[3]['roas']);
        $this->assertSame(0.0, $googleAdsTrend[3]['roas_change']);
        $this->assertSame(5.0, $googleAdsTrend[4]['roas']);
        $this->assertNull($googleAdsTrend[4]['roas_change']);
    }

    public function test_campaign_report_keeps_only_the_latest_thirty_snapshots_in_time_order(): void
    {
        $user = User::factory()->create();
        $connection = MetaMarketingConnection::factory()->create(['user_id' => $user->id]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'History campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ]);

        for ($snapshotIndex = 0; $snapshotIndex < 32; $snapshotIndex++) {
            $this->createMetaSnapshot(
                $target,
                $connection,
                now()->setDate(2026, 11, 22)->startOfDay()->addMinutes($snapshotIndex)->toDateTimeString(),
                ['spend_amount' => number_format(100 + $snapshotIndex, 4, '.', '')],
            );
        }

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $metaTrend = $response->viewData('metaMarketingTrend');

        $this->assertCount(30, $metaTrend);
        $this->assertSame('102.0000', $metaTrend[0]['spend_amount']);
        $this->assertSame('131.0000', $metaTrend[29]['spend_amount']);
        $this->assertTrue($metaTrend[0]['synced_at']->lessThan($metaTrend[29]['synced_at']));
    }

    public function test_paid_media_history_shows_unavailable_changes_when_metrics_have_zero_denominators(): void
    {
        $user = User::factory()->create();
        $connection = MetaMarketingConnection::factory()->create(['user_id' => $user->id]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Zero denominator campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ]);
        $this->createMetaSnapshot($target, $connection, '2026-11-20 06:00:00', [
            'spend_amount' => '0.0000',
            'impressions' => 0,
            'reach' => 0,
            'clicks' => 0,
        ]);
        $this->createMetaSnapshot($target, $connection, '2026-11-21 06:00:00', [
            'spend_amount' => '0.0000',
            'impressions' => 0,
            'reach' => 0,
            'clicks' => 0,
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Zero denominator campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '0.0000',
            'impressions' => 0,
            'reach' => 0,
            'clicks' => 0,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('無法比較');
        $metaTrend = $response->viewData('metaMarketingTrend');

        $this->assertCount(2, $metaTrend);
        $this->assertNull($metaTrend[1]['frequency']);
        $this->assertNull($metaTrend[1]['frequency_change']);
        $this->assertNull($metaTrend[1]['ctr_percent']);
        $this->assertNull($metaTrend[1]['ctr_change_percentage_points']);
        $this->assertNull($metaTrend[1]['cost_per_click']);
        $this->assertNull($metaTrend[1]['cost_per_thousand_impressions']);
    }

    public function test_campaign_report_marks_meta_and_google_ads_reads_stale_after_one_day(): void
    {
        $this->travelTo('2026-11-22 08:00:00');
        $user = User::factory()->create();
        $metaConnection = MetaMarketingConnection::factory()->create(['user_id' => $user->id]);
        $googleAdsConnection = GoogleAdsConnection::factory()->create(['user_id' => $user->id]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Freshness campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
            'google_ads_campaign_id' => '987654322',
        ]);
        $this->createCurrentReports($target, $metaConnection, $googleAdsConnection);
        $metaReport = SocialEngagementTargetMetaMarketingReport::query()->sole();
        $googleAdsReport = SocialEngagementTargetGoogleAdsReport::query()->sole();
        $metaReport->update(['synced_at' => now()->subDay()->subSecond()]);
        $googleAdsReport->update(['synced_at' => now()->subDay()->subSecond()]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 超過 24 小時未成功更新')
            ->assertSee('Google Ads 超過 24 小時未成功更新');

        $metaReport->update(['synced_at' => now()->subDay()->addSecond()]);
        $googleAdsReport->update(['synced_at' => now()->subDay()->addSecond()]);

        $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertDontSee('Meta Ads 超過 24 小時未成功更新')
            ->assertDontSee('Google Ads 超過 24 小時未成功更新');
    }

    public function test_meta_paid_media_history_export_is_scoped_and_formula_safe(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $connection = MetaMarketingConnection::factory()->create(['user_id' => $user->id]);
        $otherConnection = MetaMarketingConnection::factory()->create(['user_id' => $otherUser->id]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Export campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ]);

        $this->createMetaSnapshot($target, $connection, '2026-11-20 06:00:00', [
            'campaign_name' => '=SUM(1+1)',
            'spend_amount' => '100.0000',
            'action_values' => [
                ['action_type' => 'omni_purchase', 'value' => '400.0000'],
            ],
        ]);
        $this->createMetaSnapshot($target, $connection, '2026-11-21 06:00:00', [
            'campaign_name' => '=SUM(1+1)',
            'spend_amount' => '150.0000',
            'actions' => [
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '4.0000'],
            ],
            'action_values' => [
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '200.0000'],
                ['action_type' => 'purchase', 'value' => '9000.0000'],
                ['action_type' => 'omni_purchase', 'value' => '450.0000'],
            ],
        ]);
        $this->createMetaSnapshot($target, $otherConnection, '2026-11-21 07:00:00', [
            'campaign_name' => 'Private other account',
            'spend_amount' => '99999.0000',
        ]);
        $this->createMetaSnapshot($target, $connection, '2026-11-21 08:00:00', [
            'campaign_id' => '987654329',
            'campaign_name' => 'Different campaign',
            'spend_amount' => '88888.0000',
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.paid-media.history', [$target, 'meta-marketing']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('meta-marketing-campaign-trend-'.$target->id.'.csv', (string) $response->headers->get('content-disposition'));
        $csvLines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));
        $csvRows = array_map(static fn (string $line): array => str_getcsv($line), $csvLines);

        $this->assertCount(3, $csvRows);
        $this->assertSame('source', $csvRows[0][0]);
        $this->assertSame('Meta Ads', $csvRows[1][0]);
        $this->assertSame("'=SUM(1+1)", $csvRows[1][1]);
        $this->assertSame('50.0000', $csvRows[2][4]);
        $frequencyIndex = array_search('frequency_cumulative', $csvRows[0], true);
        $frequencyChangeIndex = array_search('frequency_change_since_previous', $csvRows[0], true);
        $roasValueIndex = array_search('roas_value_cumulative', $csvRows[0], true);
        $roasIndex = array_search('roas', $csvRows[0], true);
        $roasChangeIndex = array_search('roas_change_since_previous', $csvRows[0], true);
        $ctrIndex = array_search('ctr_percent', $csvRows[0], true);
        $cpcChangeIndex = array_search('cost_per_click_change_since_previous', $csvRows[0], true);
        $cpmChangeIndex = array_search('cost_per_thousand_impressions_change_since_previous', $csvRows[0], true);
        $this->assertIsInt($frequencyIndex);
        $this->assertIsInt($frequencyChangeIndex);
        $this->assertIsInt($roasValueIndex);
        $this->assertIsInt($roasIndex);
        $this->assertIsInt($roasChangeIndex);
        $this->assertIsInt($ctrIndex);
        $this->assertIsInt($cpcChangeIndex);
        $this->assertIsInt($cpmChangeIndex);
        $actionsIndex = array_search('actions', $csvRows[0], true);
        $actionValuesIndex = array_search('action_values', $csvRows[0], true);
        $this->assertIsInt($actionsIndex);
        $this->assertIsInt($actionValuesIndex);
        $this->assertSame(
            '[{"action_type":"offsite_conversion.fb_pixel_purchase","value":"4.0000"}]',
            $csvRows[2][$actionsIndex],
        );
        $this->assertSame(
            '[{"action_type":"offsite_conversion.fb_pixel_purchase","value":"200.0000"},{"action_type":"purchase","value":"9000.0000"},{"action_type":"omni_purchase","value":"450.0000"}]',
            $csvRows[2][$actionValuesIndex],
        );
        $this->assertSame('1.2500', $csvRows[1][$frequencyIndex]);
        $this->assertSame('0.0000', $csvRows[2][$frequencyChangeIndex]);
        $this->assertSame('2.00', $csvRows[1][$ctrIndex]);
        $this->assertSame('2.5000', $csvRows[2][$cpcChangeIndex]);
        $this->assertSame('50.0000', $csvRows[2][$cpmChangeIndex]);
        $this->assertSame('450.0000', $csvRows[2][$roasValueIndex]);
        $this->assertSame('3.00', $csvRows[2][$roasIndex]);
        $this->assertSame('-1.00', $csvRows[2][$roasChangeIndex]);
        $this->assertStringNotContainsString('Private other account', $response->streamedContent());
        $this->assertStringNotContainsString('Different campaign', $response->streamedContent());
    }

    public function test_google_ads_paid_media_history_export_includes_conversion_deltas(): void
    {
        $user = User::factory()->create();
        $connection = GoogleAdsConnection::factory()->create(['user_id' => $user->id]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Google export campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
        ]);
        $client = Client::ensureDefaultFor($user);
        $target->forceFill([
            'user_id' => $user->workspaceOwnerId(),
            'client_id' => $client->getKey(),
        ])->save();
        $connection->clientAccounts()->create([
            'client_id' => $client->getKey(),
            'customer_id' => $connection->customer_id,
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);
        $this->createGoogleAdsSnapshot($target, $connection, '2026-11-20 06:00:00', [
            'spend_amount' => '100.0000',
            'conversions' => '2.0000',
            'conversions_value' => '80.0000',
        ]);
        $this->createGoogleAdsSnapshot($target, $connection, '2026-11-21 06:00:00', [
            'spend_amount' => '150.0000',
            'conversions' => '5.0000',
            'conversions_value' => '200.0000',
            'conversion_actions' => [
                [
                    'conversion_action_id' => '1001',
                    'conversion_action_name' => 'Purchase',
                    'conversions' => '4.0000',
                    'conversion_value' => '190.0000',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.paid-media.history', [$target, 'google-ads']))
            ->assertOk();
        $csvLines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));
        $csvRows = array_map(static fn (string $line): array => str_getcsv($line), $csvLines);

        $this->assertCount(3, $csvRows);
        $this->assertSame('Google Ads', $csvRows[1][0]);
        $conversionChangeIndex = array_search('conversions_change_since_previous', $csvRows[0], true);
        $conversionValueChangeIndex = array_search('conversions_value_change_since_previous', $csvRows[0], true);
        $conversionCostChangeIndex = array_search('cost_per_conversion_change_since_previous', $csvRows[0], true);
        $conversionRateIndex = array_search('conversion_rate_percent', $csvRows[0], true);
        $clickCostChangeIndex = array_search('cost_per_click_change_since_previous', $csvRows[0], true);
        $conversionActionsIndex = array_search('conversion_actions', $csvRows[0], true);
        $roasIndex = array_search('roas', $csvRows[0], true);
        $roasChangeIndex = array_search('roas_change_since_previous', $csvRows[0], true);
        $this->assertIsInt($conversionChangeIndex);
        $this->assertIsInt($conversionValueChangeIndex);
        $this->assertIsInt($conversionCostChangeIndex);
        $this->assertIsInt($conversionRateIndex);
        $this->assertIsInt($clickCostChangeIndex);
        $this->assertIsInt($conversionActionsIndex);
        $this->assertIsInt($roasIndex);
        $this->assertIsInt($roasChangeIndex);
        $this->assertSame('3.0000', $csvRows[2][$conversionChangeIndex]);
        $this->assertSame('120.0000', $csvRows[2][$conversionValueChangeIndex]);
        $this->assertSame('-20.0000', $csvRows[2][$conversionCostChangeIndex]);
        $this->assertSame('50.00', $csvRows[2][$conversionRateIndex]);
        $this->assertSame('5.0000', $csvRows[2][$clickCostChangeIndex]);
        $this->assertSame('1.33', $csvRows[2][$roasIndex]);
        $this->assertSame('0.53', $csvRows[2][$roasChangeIndex]);
        $this->assertSame(
            '[{"conversion_action_id":"1001","conversion_action_name":"Purchase","conversions":"4.0000","conversion_value":"190.0000"}]',
            $csvRows[2][$conversionActionsIndex],
        );
    }

    public function test_paid_media_history_export_requires_authentication_and_rejects_unknown_sources(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->get(route('social-engagement-targets.paid-media.history', [$target, 'meta-marketing']))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get('/social-engagement-targets/'.$target->id.'/paid-media-history/unknown.csv')
            ->assertNotFound();
    }

    /** @param array<string, mixed> $attributes */
    private function createMetaSnapshot(
        SocialEngagementTarget $target,
        MetaMarketingConnection $connection,
        string $syncedAt,
        array $attributes = [],
    ): SocialEngagementTargetPaidAdSnapshot {
        if ($target->user_id === null) {
            $user = User::query()->findOrFail($connection->user_id);
            $target->forceFill([
                'user_id' => $user->id,
                'client_id' => Client::ensureDefaultFor($user)->id,
            ])->save();
        }

        if ((int) $target->user_id === (int) $connection->user_id && $target->client_id !== null) {
            MetaMarketingClientAccount::withoutGlobalScopes()->firstOrCreate([
                'meta_marketing_connection_id' => $connection->id,
                'client_id' => $target->client_id,
            ], [
                'ad_account_id' => $connection->ad_account_id,
                'ad_account_name' => $connection->ad_account_name,
                'currency_code' => $connection->currency_code,
            ]);
        }

        return SocialEngagementTargetPaidAdSnapshot::factory()
            ->for($target, 'target')
            ->forMetaMarketing($connection)
            ->create([
                'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
                'account_id' => $connection->ad_account_id,
                'campaign_id' => $target->meta_campaign_id,
                'campaign_name' => 'Winter product launch',
                'starts_on' => $target->starts_on,
                'ends_on' => $target->ends_on,
                'spend_amount' => '100.0000',
                'impressions' => 1000,
                'reach' => 800,
                'clicks' => 20,
                'currency_code' => 'TWD',
                'synced_at' => $syncedAt,
                ...$attributes,
            ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createGoogleAdsSnapshot(
        SocialEngagementTarget $target,
        GoogleAdsConnection $connection,
        string $syncedAt,
        array $attributes = [],
    ): SocialEngagementTargetPaidAdSnapshot {
        return SocialEngagementTargetPaidAdSnapshot::factory()
            ->for($target, 'target')
            ->forGoogleAds($connection)
            ->create([
                'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_GOOGLE_ADS,
                'account_id' => $connection->customer_id,
                'campaign_id' => $target->google_ads_campaign_id,
                'campaign_name' => 'Winter product launch',
                'starts_on' => $target->starts_on,
                'ends_on' => $target->ends_on,
                'spend_amount' => '50.0000',
                'impressions' => 500,
                'reach' => null,
                'clicks' => 10,
                'conversions' => '1.0000',
                'conversions_value' => '40.0000',
                'currency_code' => 'TWD',
                'synced_at' => $syncedAt,
                ...$attributes,
            ]);
    }

    private function createCurrentReports(
        SocialEngagementTarget $target,
        MetaMarketingConnection $metaConnection,
        GoogleAdsConnection $googleAdsConnection,
    ): void {
        if ($target->user_id === null) {
            $user = User::query()->findOrFail($metaConnection->user_id);
            $target->forceFill([
                'user_id' => $user->id,
                'client_id' => Client::ensureDefaultFor($user)->id,
            ])->save();
        }

        if ((int) $target->user_id === (int) $metaConnection->user_id && $target->client_id !== null) {
            MetaMarketingClientAccount::withoutGlobalScopes()->firstOrCreate([
                'meta_marketing_connection_id' => $metaConnection->id,
                'client_id' => $target->client_id,
            ], [
                'ad_account_id' => $metaConnection->ad_account_id,
                'ad_account_name' => $metaConnection->ad_account_name,
                'currency_code' => $metaConnection->currency_code,
            ]);
        }

        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $metaConnection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Winter product launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '20.0000',
            'impressions' => 2000,
            'reach' => 1200,
            'clicks' => 40,
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);

        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $googleAdsConnection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $googleAdsConnection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Winter product launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '75.0000',
            'impressions' => 800,
            'clicks' => 22,
            'conversions' => '4.0000',
            'conversions_value' => '100.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
    }
}
