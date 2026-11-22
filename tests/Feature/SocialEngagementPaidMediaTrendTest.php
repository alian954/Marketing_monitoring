<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
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

        $this->createMetaSnapshot($target, $metaConnection, '2026-11-20 06:00:00', [
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 20,
            'currency_code' => 'TWD',
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-21 06:00:00', [
            'spend_amount' => '150.0000',
            'impressions' => 1600,
            'reach' => 1000,
            'clicks' => 35,
            'currency_code' => 'TWD',
        ]);
        $this->createMetaSnapshot($target, $metaConnection, '2026-11-22 06:00:00', [
            'spend_amount' => '20.0000',
            'impressions' => 2000,
            'reach' => 1200,
            'clicks' => 40,
            'currency_code' => 'USD',
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
            ->assertSee('Google Ads 歷史讀值')
            ->assertSee('Meta Ads 最近 30 次累計成效讀值與前次差異')
            ->assertSee('Google Ads 最近 30 次累計成效讀值與前次差異')
            ->assertSee(route('social-engagement-targets.paid-media.history', [$target, 'meta-marketing']), false)
            ->assertSee(route('social-engagement-targets.paid-media.history', [$target, 'google-ads']), false)
            ->assertSee('<th scope="col">讀值時間</th>', false)
            ->assertSee('2,000')
            ->assertSee('100.00 TWD');

        $metaTrend = $response->viewData('metaMarketingTrend');
        $this->assertCount(3, $metaTrend);
        $this->assertSame(50.0, $metaTrend[1]['spend_change']);
        $this->assertSame(600, $metaTrend[1]['impressions_change']);
        $this->assertSame(200, $metaTrend[1]['reach_change']);
        $this->assertSame(15, $metaTrend[1]['clicks_change']);
        $this->assertFalse($metaTrend[2]['currency_comparable']);
        $this->assertNull($metaTrend[2]['spend_change']);
        $this->assertSame(400, $metaTrend[2]['impressions_change']);

        $googleAdsTrend = $response->viewData('googleAdsTrend');
        $this->assertCount(2, $googleAdsTrend);
        $this->assertSame(25.0, $googleAdsTrend[1]['spend_change']);
        $this->assertSame(300, $googleAdsTrend[1]['impressions_change']);
        $this->assertSame(12, $googleAdsTrend[1]['clicks_change']);
        $this->assertSame(3.0, $googleAdsTrend[1]['conversions_change']);
        $this->assertSame(60.0, $googleAdsTrend[1]['conversions_value_change']);
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
        ]);
        $this->createMetaSnapshot($target, $connection, '2026-11-21 06:00:00', [
            'campaign_name' => '=SUM(1+1)',
            'spend_amount' => '150.0000',
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
        $this->createGoogleAdsSnapshot($target, $connection, '2026-11-20 06:00:00', [
            'spend_amount' => '100.0000',
            'conversions' => '2.0000',
            'conversions_value' => '80.0000',
        ]);
        $this->createGoogleAdsSnapshot($target, $connection, '2026-11-21 06:00:00', [
            'spend_amount' => '150.0000',
            'conversions' => '5.0000',
            'conversions_value' => '200.0000',
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.paid-media.history', [$target, 'google-ads']))
            ->assertOk();
        $csvLines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));
        $csvRows = array_map(static fn (string $line): array => str_getcsv($line), $csvLines);

        $this->assertCount(3, $csvRows);
        $this->assertSame('Google Ads', $csvRows[1][0]);
        $this->assertSame('3.0000', $csvRows[2][13]);
        $this->assertSame('120.0000', $csvRows[2][15]);
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
