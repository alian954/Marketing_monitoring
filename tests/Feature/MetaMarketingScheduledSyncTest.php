<?php

namespace Tests\Feature;

use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingScheduledSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_sync_refreshes_active_and_recently_finished_campaigns(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:40:00', 'Asia/Taipei'));
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push(['data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Active campaign',
                    'spend' => '125.50',
                    'impressions' => '1200',
                    'reach' => '900',
                    'clicks' => '48',
                    'actions' => [
                        ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '2'],
                    ],
                    'action_values' => [
                        ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '250.50'],
                    ],
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654322',
                    'campaign_name' => 'Recently ended campaign',
                    'spend' => '60',
                    'impressions' => '800',
                    'reach' => '700',
                    'clicks' => '20',
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $activeTarget = $this->createTarget('Active campaign', '987654321', '2026-11-01', '2026-11-30');
        $endedTarget = $this->createTarget('Recently ended campaign', '987654322', '2026-11-01', '2026-11-20');

        $exitCode = Artisan::call('meta-marketing:sync-due');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $activeTarget->id,
            'spend_amount' => '125.5000',
        ]);
        $this->assertDatabaseHas('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $endedTarget->id,
            'spend_amount' => '60.0000',
        ]);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 2);
        $snapshot = SocialEngagementTargetPaidAdSnapshot::query()
            ->where('social_engagement_target_id', $activeTarget->id)
            ->sole();
        $this->assertSame([
            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '2.0000'],
        ], $snapshot->actions);
        $this->assertSame([
            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '250.5000'],
        ], $snapshot->action_values);
        $this->assertDatabaseHas('social_engagement_target_paid_ad_snapshots', [
            'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $activeTarget->id,
            'account_id' => $connection->ad_account_id,
            'campaign_id' => '987654321',
            'spend_amount' => '125.5000',
            'reach' => 900,
            'conversions' => null,
        ]);
        $this->assertStringContainsString('完成 2 個活動', Artisan::output());
        Http::assertSentCount(10);
    }

    public function test_scheduled_sync_skips_a_fresh_snapshot(): void
    {
        $this->travelTo('2026-11-22 06:40:00');
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget('Current campaign', '987654321', '2026-11-01', '2026-11-30');
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Current campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 40,
            'currency_code' => 'TWD',
            'daily_metrics' => [
                'status' => 'complete',
                'requested_start_on' => '2026-11-01',
                'requested_end_on' => '2026-11-30',
                'coverage_start_on' => '2026-11-01',
                'coverage_end_on' => '2026-11-30',
                'rows' => [],
            ],
            'synced_at' => now()->subHours(4),
        ]);

        $exitCode = Artisan::call('meta-marketing:sync-due');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('略過 1 個近期讀值', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_scheduled_sync_appends_a_paid_media_snapshot_after_the_previous_read_goes_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:40:00', 'Asia/Taipei'));
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push(['data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Active campaign',
                    'spend' => '100.00',
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '40',
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Active campaign',
                    'spend' => '125.50',
                    'impressions' => '1200',
                    'reach' => '900',
                    'clicks' => '48',
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget('Active campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('meta-marketing:sync-due')->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 06:40:00', 'Asia/Taipei'));
        $this->artisan('meta-marketing:sync-due')->assertExitCode(0);

        $history = SocialEngagementTargetPaidAdSnapshot::query()
            ->where('social_engagement_target_id', $target->id)
            ->orderBy('synced_at')
            ->get();

        $this->assertCount(2, $history);
        $this->assertSame(['100.0000', '125.5000'], $history->pluck('spend_amount')->all());
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 1);
        $this->assertDatabaseHas('social_engagement_target_meta_marketing_reports', [
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'spend_amount' => '125.5000',
        ]);
        Http::assertSentCount(10);
    }

    public function test_scheduled_sync_does_not_request_data_for_an_expired_token(): void
    {
        $user = User::factory()->create();
        $this->createConnection($user, now()->subDay());
        $this->createTarget('Expired campaign', '987654321', '2026-11-01', '2026-11-30');

        $exitCode = Artisan::call('meta-marketing:sync-due');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('失敗 0 個', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_failed_meta_sync_does_not_store_a_report_or_trend_snapshot(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'error' => ['code' => 10, 'message' => 'Permission denied.'],
            ], 403),
        ]);
        $user = User::factory()->create();
        $this->createConnection($user);
        $target = $this->createTarget('Failed Meta campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('meta-marketing:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $target->id,
        ]);
        $this->assertDatabaseMissing('social_engagement_target_paid_ad_snapshots', [
            'social_engagement_target_id' => $target->id,
        ]);
    }

    private function createConnection(User $user, mixed $tokenExpiresAt = null): MetaMarketingConnection
    {
        return MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
            'token_expires_at' => $tokenExpiresAt,
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);
    }

    private function createTarget(string $tag, string $campaignId, string $startsOn, string $endsOn): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'tag' => $tag,
            'social_platform' => 'facebook',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'meta_campaign_id' => $campaignId,
        ]);
    }
}
