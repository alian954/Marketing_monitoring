<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsScheduledSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_sync_is_scheduled_at_six_thirty_in_taipei_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'google-analytics:sync-due'));

        $this->assertNotNull($event);
        $this->assertSame('30 6 * * *', $event->expression);
        $this->assertSame('Asia/Taipei', $event->timezone);
    }

    public function test_daily_command_syncs_active_and_recently_ended_campaigns_only(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::sequence()
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload()),
        ]);

        $this->createConnection();
        $activeTarget = $this->createTarget(['tag' => 'Active campaign', 'ends_on' => '2026-11-30']);
        $recentlyEndedTarget = $this->createTarget(['tag' => 'Recent campaign', 'ends_on' => '2026-11-17']);
        $oldTarget = $this->createTarget(['tag' => 'Old campaign', 'ends_on' => '2026-11-14']);
        $futureTarget = $this->createTarget(['tag' => 'Future campaign', 'starts_on' => '2026-11-23']);

        $this->artisan('google-analytics:sync-due')
            ->expectsOutputToContain('完成 2 個活動，略過 0 個近期讀值，失敗 0 個。')
            ->assertExitCode(0);

        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $activeTarget->id,
        ]);
        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $recentlyEndedTarget->id,
        ]);
        $this->assertDatabaseCount('google_analytics_campaign_snapshots', 2);
        $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
            'social_engagement_target_id' => $activeTarget->id,
            'sessions' => 0,
        ]);
        $this->assertDatabaseMissing('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $oldTarget->id,
        ]);
        $this->assertDatabaseMissing('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $futureTarget->id,
        ]);
        Http::assertSentCount(9);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && in_array($request->data()['dimensionFilter']['filter']['stringFilter']['value'], [
                'Active campaign',
                'Recent campaign',
            ], true));
    }

    public function test_daily_command_skips_a_matching_snapshot_synced_within_24_hours(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::fake();

        $connection = $this->createConnection();
        $target = $this->createTarget();
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 12,
            'engaged_sessions' => 8,
            'key_events' => '2.0000',
            'total_revenue' => '500.0000',
            'currency_code' => 'TWD',
            'daily_metrics' => [
                'status' => 'complete',
                'requested_start_on' => $target->starts_on->toDateString(),
                'requested_end_on' => $target->ends_on->toDateString(),
                'coverage_start_on' => $target->starts_on->toDateString(),
                'coverage_end_on' => $target->ends_on->toDateString(),
                'rows' => [],
            ],
            'synced_at' => now()->subHours(4),
        ]);

        $this->artisan('google-analytics:sync-due')
            ->expectsOutputToContain('完成 0 個活動，略過 1 個近期讀值，失敗 0 個。')
            ->assertExitCode(0);

        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 1);
        Http::assertNothingSent();
    }

    public function test_daily_sync_refreshes_a_recent_snapshot_when_the_medium_filter_changes(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::sequence()
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload()),
        ]);

        $connection = $this->createConnection();
        $target = $this->createTarget(['ga4_medium_name' => 'paid_social']);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'source_name' => $target->googleAnalyticsSourceName(),
            'medium_name' => 'organic_social',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 12,
            'engaged_sessions' => 8,
            'key_events' => '2.0000',
            'total_revenue' => '500.0000',
            'currency_code' => 'TWD',
            'synced_at' => now()->subHours(4),
        ]);

        $this->artisan('google-analytics:sync-due')
            ->expectsOutputToContain('完成 1 個活動，略過 0 個近期讀值，失敗 0 個。')
            ->assertExitCode(0);

        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $target->id,
            'medium_name' => 'paid_social',
        ]);
        Http::assertSentCount(5);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['fieldName'] === 'sessionManualMedium'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['stringFilter']['value'] === 'paid_social');
    }

    public function test_daily_sync_refreshes_a_snapshot_that_reaches_the_twenty_four_hour_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:30:00', 'Asia/Taipei'));
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::sequence()
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload()),
        ]);
        $this->createConnection();
        $target = $this->createTarget();

        $this->artisan('google-analytics:sync-due')->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 06:30:00', 'Asia/Taipei'));
        $this->artisan('google-analytics:sync-due')->assertExitCode(0);

        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 1);
        $this->assertDatabaseCount('google_analytics_campaign_snapshots', 2);
        $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
            'social_engagement_target_id' => $target->id,
            'snapshot_date' => '2026-11-23',
        ]);
        Http::assertSentCount(10);
    }

    public function test_daily_command_continues_after_a_campaign_report_error(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::sequence()
                ->push(['error' => ['message' => 'RESOURCE_EXHAUSTED']], 429)
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload()),
        ]);

        $this->createConnection();
        $failedTarget = $this->createTarget(['tag' => 'Failed campaign']);
        $successfulTarget = $this->createTarget(['tag' => 'Successful campaign']);

        $this->artisan('google-analytics:sync-due')
            ->expectsOutputToContain('完成 1 個活動，略過 0 個近期讀值，失敗 1 個。')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $failedTarget->id,
        ]);
        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $successfulTarget->id,
        ]);
        Http::assertSentCount(6);
    }

    /** @param array<string, mixed> $attributes */
    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($attributes + [
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
    }

    private function createConnection(): GoogleAnalyticsConnection
    {
        return GoogleAnalyticsConnection::query()->create([
            'user_id' => User::factory()->create()->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyReportPayload(): array
    {
        return [
            'rows' => [],
            'metadata' => ['currencyCode' => 'TWD'],
        ];
    }
}
