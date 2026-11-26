<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingScheduledSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_meta_marketing_sync_schedule_prevents_overlapping_runs(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'meta-marketing:sync-due'));

        $this->assertNotNull($event);
        $this->assertSame('40 6 * * *', $event->expression);
        $this->assertSame('Asia/Taipei', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt);
    }

    public function test_scheduled_sync_skips_disabled_and_expired_organization_targets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:40:00', 'Asia/Taipei'));
        Http::preventStrayRequests();

        foreach ([
            ['is_enabled' => false, 'subscription_ends_on' => null],
            ['is_enabled' => true, 'subscription_ends_on' => '2026-11-21'],
        ] as $organizationAttributes) {
            $user = User::factory()->create();
            Organization::factory()->for($user, 'owner')->create($organizationAttributes);
            $client = Client::ensureDefaultFor($user);
            $connection = $this->createConnection($user);
            MetaMarketingClientAccount::query()->create([
                'meta_marketing_connection_id' => $connection->getKey(),
                'client_id' => $client->getKey(),
                'ad_account_id' => '123456789',
                'ad_account_name' => 'Example Ads',
                'currency_code' => 'TWD',
            ]);
            SocialEngagementTarget::factory()->create([
                'user_id' => $user->getKey(),
                'client_id' => $client->getKey(),
                'tag' => 'Blocked campaign',
                'social_platform' => 'facebook',
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'meta_campaign_id' => '987654321',
            ]);
        }

        $this->artisan('meta-marketing:sync-due')
            ->expectsOutput('目前沒有需要更新 Meta Ads 的活動。')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 0);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 0);
    }

    public function test_scheduled_sync_refreshes_only_campaigns_in_the_connection_workspace(): void
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
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $activeTarget = $this->createTarget($user, 'Active campaign', '987654321', '2026-11-01', '2026-11-30');
        $endedTarget = $this->createTarget($user, 'Recently ended campaign', '987654322', '2026-11-01', '2026-11-20');
        $foreignUser = User::factory()->create();
        $foreignTarget = $this->createTarget($foreignUser, 'Other workspace campaign', '987654323', '2026-11-01', '2026-11-30');

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
        $this->assertDatabaseMissing('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $foreignTarget->id,
        ]);
        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(2, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_skipped_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        $this->assertNotNull($syncHealth->scheduled_sync_started_at);
        $this->assertNotNull($syncHealth->scheduled_sync_finished_at);
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 2);
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
        Http::assertSentCount(12);
    }

    public function test_scheduled_sync_skips_a_fresh_snapshot(): void
    {
        $this->travelTo('2026-11-22 06:40:00');
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget($user, 'Current campaign', '987654321', '2026-11-01', '2026-11-30');
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
        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(0, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(1, $syncHealth->scheduled_sync_skipped_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        Http::assertNothingSent();
    }

    public function test_scheduled_sync_skips_unconfigured_clients_without_reading_the_wrong_account(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:40:00', 'Asia/Taipei'));
        Http::fake([
            'https://graph.facebook.com/v27.0/act_444444444/insights*' => Http::sequence()
                ->push(['data' => [[
                    'account_id' => '444444444',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Configured client campaign',
                    'spend' => '50.00',
                    'impressions' => '500',
                    'reach' => '400',
                    'clicks' => '20',
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);

        $user = User::factory()->create();
        $clientA = $user->clients()->create(['name' => '已設定客戶']);
        $clientB = $user->clients()->create(['name' => '待設定客戶']);
        $connection = $this->createConnection($user);
        MetaMarketingClientAccount::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'client_id' => $clientA->id,
            'ad_account_id' => '444444444',
            'ad_account_name' => 'Configured account',
            'currency_code' => 'TWD',
        ]);
        $configuredTarget = SocialEngagementTarget::factory()->create([
            'user_id' => $user->id,
            'client_id' => $clientA->id,
            'tag' => 'Configured client campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ]);
        $unconfiguredTarget = SocialEngagementTarget::factory()->create([
            'user_id' => $user->id,
            'client_id' => $clientB->id,
            'tag' => 'Unconfigured client campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654322',
        ]);

        $this->artisan('meta-marketing:sync-due')
            ->expectsOutputToContain('完成 1 個活動')
            ->assertExitCode(0);

        $this->assertDatabaseHas('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $configuredTarget->id,
            'ad_account_id' => '444444444',
        ]);
        $this->assertDatabaseMissing('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $unconfiguredTarget->id,
        ]);
        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(1, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(1, $syncHealth->scheduled_sync_skipped_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        Http::assertSentCount(6);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/act_444444444/insights'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/act_123456789/insights'));
    }

    public function test_scheduled_sync_uses_each_clients_bound_account_and_skips_unconfigured_clients(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:40:00', 'Asia/Taipei'));
        $campaignsByAccount = [
            '111111111' => ['campaign_id' => '987654321', 'campaign_name' => 'North campaign', 'spend' => '111.00'],
            '222222222' => ['campaign_id' => '987654322', 'campaign_name' => 'South campaign', 'spend' => '222.00'],
        ];
        $campaignFields = 'account_id,campaign_id,campaign_name,spend,impressions,reach,clicks,actions,action_values,account_currency';
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) use ($campaignsByAccount, $campaignFields) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (! is_string($path) || preg_match('#/act_(\d+)/insights$#', $path, $matches) !== 1) {
                return Http::response([], 404);
            }

            $accountId = $matches[1];
            $campaign = $campaignsByAccount[$accountId] ?? null;
            $parameters = $request->data();

            if ($campaign !== null && ($parameters['fields'] ?? null) === $campaignFields) {
                return Http::response(['data' => [[
                    'account_id' => $accountId,
                    'campaign_id' => $campaign['campaign_id'],
                    'campaign_name' => $campaign['campaign_name'],
                    'spend' => $campaign['spend'],
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '40',
                    'account_currency' => 'TWD',
                ]]]);
            }

            return Http::response(['data' => []]);
        });

        $user = User::factory()->create();
        $northClient = $user->clients()->create(['name' => 'North client']);
        $southClient = $user->clients()->create(['name' => 'South client']);
        $unconfiguredClient = $user->clients()->create(['name' => '待設定客戶']);
        $connection = $this->createConnection($user);

        foreach ([
            [$northClient, '111111111', 'North Ads'],
            [$southClient, '222222222', 'South Ads'],
        ] as [$client, $accountId, $accountName]) {
            MetaMarketingClientAccount::query()->create([
                'meta_marketing_connection_id' => $connection->getKey(),
                'client_id' => $client->getKey(),
                'ad_account_id' => $accountId,
                'ad_account_name' => $accountName,
                'currency_code' => 'TWD',
            ]);
        }

        $targetsByCampaign = [];
        foreach ([
            [$northClient, 'North campaign', '987654321'],
            [$southClient, 'South campaign', '987654322'],
            [$unconfiguredClient, 'Unconfigured campaign', '987654323'],
        ] as [$client, $campaignName, $campaignId]) {
            $target = SocialEngagementTarget::factory()->create([
                'user_id' => $user->getKey(),
                'client_id' => $client->getKey(),
                'tag' => $campaignName,
                'social_platform' => 'facebook',
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'meta_campaign_id' => $campaignId,
            ]);
            $targetsByCampaign[$campaignName] = $target;
        }

        $this->artisan('meta-marketing:sync-due')
            ->expectsOutputToContain('完成 2 個活動，略過 1 個近期讀值，失敗 0 個。')
            ->assertExitCode(0);

        foreach ([
            ['North campaign', '111111111', '111.0000'],
            ['South campaign', '222222222', '222.0000'],
        ] as [$campaignName, $accountId, $spendAmount]) {
            $target = $targetsByCampaign[$campaignName];
            $this->assertDatabaseHas('social_engagement_target_meta_marketing_reports', [
                'meta_marketing_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
                'ad_account_id' => $accountId,
                'campaign_name' => $campaignName,
                'spend_amount' => $spendAmount,
            ]);
            $this->assertDatabaseHas('social_engagement_target_paid_ad_snapshots', [
                'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
                'meta_marketing_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
                'account_id' => $accountId,
                'spend_amount' => $spendAmount,
            ]);
            Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/act_'.$accountId.'/insights')
                && ($request->data()['fields'] ?? null) === $campaignFields
                && str_contains($request->data()['filtering'] ?? '', $target->meta_campaign_id));
        }

        $this->assertDatabaseMissing('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $targetsByCampaign['Unconfigured campaign']->getKey(),
        ]);
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 2);
        $this->assertSame('succeeded', $connection->fresh()->scheduled_sync_status);
        $this->assertSame(2, $connection->fresh()->scheduled_sync_synced_count);
        $this->assertSame(1, $connection->fresh()->scheduled_sync_skipped_count);
        $this->assertSame(0, $connection->fresh()->scheduled_sync_failed_count);

        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/act_111111111/insights')
            && str_contains($request->data()['filtering'] ?? '', '987654322'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/act_222222222/insights')
            && str_contains($request->data()['filtering'] ?? '', '987654321'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/act_123456789/insights'));
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
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget($user, 'Active campaign', '987654321', '2026-11-01', '2026-11-30');

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
        Http::assertSentCount(12);
    }

    public function test_scheduled_sync_records_an_error_without_requesting_data_for_an_expired_token(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:40:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $this->createConnection($user, now()->subDay());
        $target = $this->createTarget($user, 'Expired campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('meta-marketing:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $target->id,
        ]);
        $syncHealth = $connection->fresh();
        $this->assertSame('failed', $syncHealth->scheduled_sync_status);
        $this->assertSame(1, $syncHealth->scheduled_sync_failed_count);
        $this->assertSame('Meta Ads 授權已過期，請重新連結帳戶。', $syncHealth->scheduled_sync_error);
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
        $connection = $this->createConnection($user);
        $target = $this->createTarget($user, 'Failed Meta campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('meta-marketing:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $target->id,
        ]);
        $this->assertDatabaseMissing('social_engagement_target_paid_ad_snapshots', [
            'social_engagement_target_id' => $target->id,
        ]);
        $syncHealth = $connection->fresh();
        $this->assertSame('failed', $syncHealth->scheduled_sync_status);
        $this->assertSame(0, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(1, $syncHealth->scheduled_sync_failed_count);
        $this->assertNotEmpty($syncHealth->scheduled_sync_error);
    }

    public function test_meta_sync_clears_provider_error_after_a_later_successful_run(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:40:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push(['error' => ['code' => 10, 'message' => 'Permission denied.']], 403)
                ->push(['data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Recovered campaign',
                    'spend' => '125.50',
                    'impressions' => '1200',
                    'reach' => '900',
                    'clicks' => '48',
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget($user, 'Recovered campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('meta-marketing:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertSame('failed', $connection->fresh()->scheduled_sync_status);
        $this->assertNotEmpty($connection->fresh()->scheduled_sync_error);

        $this->travelTo(CarbonImmutable::parse('2026-11-23 06:40:00', 'Asia/Taipei'));

        $this->artisan('meta-marketing:sync-due')
            ->expectsOutputToContain('完成 1 個活動')
            ->assertExitCode(0);

        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(1, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        $this->assertNull($syncHealth->scheduled_sync_error);
        $this->assertDatabaseHas('social_engagement_target_meta_marketing_reports', [
            'social_engagement_target_id' => $target->id,
            'spend_amount' => '125.5000',
        ]);
        Http::assertSentCount(7);
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

    private function createTarget(User $user, string $tag, string $campaignId, string $startsOn, string $endsOn): SocialEngagementTarget
    {
        $client = Client::ensureDefaultFor($user);

        return SocialEngagementTarget::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'tag' => $tag,
            'social_platform' => 'facebook',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'meta_campaign_id' => $campaignId,
        ]);
    }
}
