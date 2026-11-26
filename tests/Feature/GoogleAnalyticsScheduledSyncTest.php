<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class GoogleAnalyticsScheduledSyncTest extends TestCase
{
    use RefreshDatabase;

    private ?int $workspaceUserId = null;

    private ?int $workspaceClientId = null;

    public function test_daily_sync_is_scheduled_at_six_thirty_in_taipei_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'google-analytics:sync-due'));

        $this->assertNotNull($event);
        $this->assertSame('30 6 * * *', $event->expression);
        $this->assertSame('Asia/Taipei', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt);
    }

    public function test_daily_sync_skips_disabled_and_expired_organization_targets(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();

        foreach ([
            ['is_enabled' => false, 'subscription_ends_on' => null],
            ['is_enabled' => true, 'subscription_ends_on' => '2026-11-21'],
        ] as $organizationAttributes) {
            $user = User::factory()->create();
            Organization::factory()->for($user, 'owner')->create($organizationAttributes);
            $client = Client::ensureDefaultFor($user);
            GoogleAnalyticsConnection::query()->create([
                'user_id' => $user->getKey(),
                'property_id' => '123456789',
                'refresh_token' => 'refresh-token',
            ]);
            SocialEngagementTarget::factory()->create([
                'user_id' => $user->getKey(),
                'client_id' => $client->getKey(),
                'tag' => 'Blocked campaign',
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
            ]);
        }

        $this->artisan('google-analytics:sync-due')
            ->expectsOutput('目前沒有需要更新 GA4 的活動。')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 0);
    }

    public function test_daily_command_syncs_only_campaigns_in_the_connection_workspace(): void
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
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload()),
        ]);

        $connection = $this->createConnection();
        $activeTarget = $this->createTarget(['tag' => 'Active campaign', 'ends_on' => '2026-11-30']);
        $recentlyEndedTarget = $this->createTarget(['tag' => 'Recent campaign', 'ends_on' => '2026-11-17']);
        $oldTarget = $this->createTarget(['tag' => 'Old campaign', 'ends_on' => '2026-11-14']);
        $futureTarget = $this->createTarget(['tag' => 'Future campaign', 'starts_on' => '2026-11-23']);
        $foreignTarget = $this->createTarget(['tag' => 'Other workspace campaign']);
        foreach ([$activeTarget, $recentlyEndedTarget, $oldTarget, $futureTarget] as $target) {
            $target->forceFill(['user_id' => $connection->user_id])->save();
        }
        $foreignUser = User::factory()->create();
        $foreignTarget->forceFill([
            'user_id' => $foreignUser->id,
            'client_id' => Client::ensureDefaultFor($foreignUser)->id,
        ])->save();

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
        $this->assertDatabaseMissing('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $foreignTarget->id,
        ]);
        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 2);
        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(2, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_skipped_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        $this->assertNotNull($syncHealth->scheduled_sync_started_at);
        $this->assertNotNull($syncHealth->scheduled_sync_finished_at);
        Http::assertSentCount(15);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && in_array($request->data()['dimensionFilter']['filter']['stringFilter']['value'], [
                'Active campaign',
                'Recent campaign',
            ], true));
    }

    public function test_daily_command_syncs_each_client_using_its_bound_property_and_skips_unconfigured_clients(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/*:runReport' => Http::response($this->emptyReportPayload()),
        ]);

        $connection = $this->createConnection();
        $user = User::query()->findOrFail($this->workspaceUserId);
        $defaultClient = Client::query()->findOrFail($this->workspaceClientId);
        $clientB = $user->clients()->create(['name' => 'Client B']);
        $unconfiguredClient = $user->clients()->create(['name' => '待設定客戶']);
        GoogleAnalyticsClientProperty::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'client_id' => $defaultClient->getKey(),
            'property_id' => '111111111',
        ]);
        GoogleAnalyticsClientProperty::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'client_id' => $clientB->getKey(),
            'property_id' => '222222222',
        ]);

        $targetA = $this->createTarget(['tag' => 'Client A campaign']);
        $targetB = $this->createTarget([
            'tag' => 'Client B campaign',
            'client_id' => $clientB->getKey(),
        ]);
        $unconfiguredTarget = $this->createTarget([
            'tag' => 'Unconfigured campaign',
            'client_id' => $unconfiguredClient->getKey(),
        ]);

        $this->artisan('google-analytics:sync-due')
            ->expectsOutputToContain('完成 2 個活動，略過 1 個近期讀值，失敗 0 個。')
            ->assertExitCode(0);

        foreach ([[$targetA, '111111111'], [$targetB, '222222222']] as [$target, $propertyId]) {
            $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
                'google_analytics_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
                'property_id' => $propertyId,
            ]);
            $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
                'google_analytics_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
                'property_id' => $propertyId,
            ]);
        }

        $this->assertDatabaseMissing('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $unconfiguredTarget->getKey(),
        ]);
        $this->assertSame('succeeded', $connection->fresh()->scheduled_sync_status);
        $this->assertSame(2, $connection->fresh()->scheduled_sync_synced_count);
        $this->assertSame(1, $connection->fresh()->scheduled_sync_skipped_count);
        $this->assertSame(0, $connection->fresh()->scheduled_sync_failed_count);

        Http::assertSentCount(15);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/111111111:runReport')
            && $request->data()['dimensionFilter']['filter']['stringFilter']['value'] === 'Client A campaign');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/222222222:runReport')
            && $request->data()['dimensionFilter']['filter']['stringFilter']['value'] === 'Client B campaign');
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/111111111:runReport')
            && $request->data()['dimensionFilter']['filter']['stringFilter']['value'] === 'Client B campaign');
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/222222222:runReport')
            && $request->data()['dimensionFilter']['filter']['stringFilter']['value'] === 'Client A campaign');
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/123456789:runReport'));
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
        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(0, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(1, $syncHealth->scheduled_sync_skipped_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
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
        Http::assertSentCount(8);
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
        Http::assertSentCount(16);
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
                ->push(['error' => ['message' => 'RESOURCE_EXHAUSTED']], 429)
                ->push(['error' => ['message' => 'RESOURCE_EXHAUSTED']], 429)
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload())
                ->push($this->emptyReportPayload()),
        ]);

        $connection = $this->createConnection();
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
        $syncHealth = $connection->fresh();
        $this->assertSame('partial', $syncHealth->scheduled_sync_status);
        $this->assertSame(1, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(1, $syncHealth->scheduled_sync_failed_count);
        $this->assertSame('Google Analytics API 配額已用盡，請稍後再同步。', $syncHealth->scheduled_sync_error);
        Http::assertSentCount(11);
        Sleep::assertSequence([
            Sleep::for(250)->milliseconds(),
            Sleep::for(750)->milliseconds(),
        ]);
    }

    public function test_daily_command_records_an_actionable_error_when_ga4_refresh_token_is_revoked(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);
        $connection = $this->createConnection();
        $target = $this->createTarget(['tag' => 'Revoked token campaign']);
        $target->forceFill(['user_id' => $connection->user_id])->save();

        $this->artisan('google-analytics:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $target->id,
        ]);
        $syncHealth = $connection->fresh();
        $this->assertSame('failed', $syncHealth->scheduled_sync_status);
        $this->assertSame(1, $syncHealth->scheduled_sync_failed_count);
        $this->assertSame('Google 授權憑證已失效或已撤銷，請重新連結帳戶。', $syncHealth->scheduled_sync_error);
        Http::assertSentCount(1);
    }

    public function test_ga4_sync_clears_provider_error_after_a_later_successful_run(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::preventStrayRequests();
        $shouldFailReport = true;
        Http::fake(function (ClientRequest $request) use (&$shouldFailReport) {
            if (str_contains($request->url(), '/token')) {
                return Http::response(['access_token' => 'access-token']);
            }

            if (str_contains($request->url(), ':runReport')) {
                if ($shouldFailReport) {
                    $shouldFailReport = false;

                    return Http::response(['error' => ['message' => 'Permission denied.']], 403);
                }

                return Http::response($this->emptyReportPayload());
            }

            return Http::response([], 404);
        });

        $connection = $this->createConnection();
        $target = $this->createTarget(['tag' => 'Recovered GA4 campaign']);

        $this->artisan('google-analytics:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertSame('failed', $connection->fresh()->scheduled_sync_status);
        $this->assertNotEmpty($connection->fresh()->scheduled_sync_error);

        $this->travelTo('2026-11-23 12:00:00');

        $this->artisan('google-analytics:sync-due')
            ->expectsOutputToContain('完成 1 個活動')
            ->assertExitCode(0);

        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(1, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        $this->assertNull($syncHealth->scheduled_sync_error);
        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $target->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        $ownerAttributes = $this->workspaceUserId === null || $this->workspaceClientId === null
            ? []
            : ['user_id' => $this->workspaceUserId, 'client_id' => $this->workspaceClientId];

        return SocialEngagementTarget::factory()->create($attributes + $ownerAttributes + [
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
    }

    private function createConnection(): GoogleAnalyticsConnection
    {
        $user = User::factory()->create();
        $client = Client::ensureDefaultFor($user);
        $this->workspaceUserId = (int) $user->getKey();
        $this->workspaceClientId = (int) $client->getKey();

        return GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
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
