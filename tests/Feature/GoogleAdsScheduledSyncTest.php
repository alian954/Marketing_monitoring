<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAdsClientAccount;
use App\Models\GoogleAdsConnection;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsScheduledSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_google_ads_sync_is_scheduled_at_six_fifty_in_taipei_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'google-ads:sync-due'));

        $this->assertNotNull($event);
        $this->assertSame('50 6 * * *', $event->expression);
        $this->assertSame('Asia/Taipei', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt);
    }

    public function test_daily_command_skips_disabled_and_expired_organization_targets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:50:00', 'Asia/Taipei'));
        Http::preventStrayRequests();

        foreach ([
            ['is_enabled' => false, 'subscription_ends_on' => null],
            ['is_enabled' => true, 'subscription_ends_on' => '2026-11-21'],
        ] as $organizationAttributes) {
            $user = User::factory()->create();
            Organization::factory()->for($user, 'owner')->create($organizationAttributes);
            $client = Client::ensureDefaultFor($user);
            $connection = $this->createConnection($user);
            $connection->clientAccounts()->create([
                'client_id' => $client->getKey(),
                'customer_id' => '1234567890',
                'customer_name' => 'Example Ads',
                'currency_code' => 'TWD',
            ]);
            SocialEngagementTarget::factory()->create([
                'user_id' => $user->getKey(),
                'client_id' => $client->getKey(),
                'tag' => 'Blocked campaign',
                'social_platform' => 'facebook',
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'google_ads_campaign_id' => '987654321',
            ]);
        }

        $this->artisan('google-ads:sync-due')
            ->expectsOutput('目前沒有需要更新 Google Ads 的活動。')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 0);
    }

    public function test_daily_command_syncs_only_campaigns_in_the_connection_workspace(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:50:00', 'Asia/Taipei'));
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignPayload('987654321', 'Active campaign', '125500000', 'TWD'))
                ->push([])
                ->push($this->conversionActionsPayload('987654321'))
                ->push([])
                ->push($this->campaignPayload('987654322', 'Recently ended campaign', '60000000', 'TWD'))
                ->push([])
                ->push($this->conversionActionsPayload('987654322'))
                ->push([])
                ->push([])
                ->push([]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $activeTarget = $this->createTarget('Active campaign', '987654321', '2026-11-01', '2026-11-30');
        $recentlyEndedTarget = $this->createTarget('Recently ended campaign', '987654322', '2026-11-01', '2026-11-17');
        $oldTarget = $this->createTarget('Old campaign', '987654323', '2026-11-01', '2026-11-14');
        $futureTarget = $this->createTarget('Future campaign', '987654324', '2026-11-23', '2026-11-30');
        $foreignTarget = $this->createTarget('Other workspace campaign', '987654325', '2026-11-01', '2026-11-30');
        foreach ([$activeTarget, $recentlyEndedTarget, $oldTarget, $futureTarget] as $target) {
            $target->forceFill(['user_id' => $user->id])->save();
        }
        $foreignTarget->forceFill(['user_id' => User::factory()->create()->id])->save();

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('完成 2 個活動，略過未設定帳戶 0 個，略過近期讀值 0 個，失敗 0 個。')
            ->assertExitCode(0);

        $this->assertDatabaseHas('social_engagement_target_google_ads_reports', [
            'social_engagement_target_id' => $activeTarget->id,
            'spend_amount' => '125.5000',
        ]);
        $this->assertDatabaseHas('social_engagement_target_google_ads_reports', [
            'social_engagement_target_id' => $recentlyEndedTarget->id,
            'spend_amount' => '60.0000',
        ]);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 2);
        $this->assertDatabaseHas('social_engagement_target_paid_ad_snapshots', [
            'source' => 'google_ads',
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $activeTarget->id,
            'account_id' => $connection->customer_id,
            'campaign_id' => '987654321',
            'spend_amount' => '125.5000',
            'conversions' => '5.0000',
            'conversions_value' => '250.0000',
            'conversion_actions' => '[{"conversion_action_id":"1001","conversion_action_name":"Purchase","conversions":"5.0000","conversion_value":"250.0000"}]',
        ]);
        $this->assertDatabaseMissing('social_engagement_target_google_ads_reports', [
            'social_engagement_target_id' => $oldTarget->id,
        ]);
        $this->assertDatabaseMissing('social_engagement_target_google_ads_reports', [
            'social_engagement_target_id' => $futureTarget->id,
        ]);
        $this->assertDatabaseMissing('social_engagement_target_google_ads_reports', [
            'social_engagement_target_id' => $foreignTarget->id,
        ]);
        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 2);
        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(2, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_skipped_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        $this->assertNotNull($syncHealth->scheduled_sync_started_at);
        $this->assertNotNull($syncHealth->scheduled_sync_finished_at);
        Http::assertSentCount(9);
        Http::assertSent(function (ClientRequest $request): bool {
            return str_ends_with($request->url(), 'googleAds:searchStream')
                && in_array($request->data()['query'], [
                    "SELECT campaign.id, campaign.name, customer.id, customer.currency_code, campaign.advertising_channel_type, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = 987654321 AND segments.date BETWEEN '2026-11-01' AND '2026-11-30'",
                    "SELECT campaign.id, campaign.name, customer.id, customer.currency_code, campaign.advertising_channel_type, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = 987654322 AND segments.date BETWEEN '2026-11-01' AND '2026-11-17'",
                ], true);
        });
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), 'googleAds:searchStream')
                && str_contains($query, 'segments.conversion_action')
                && str_contains($query, 'campaign.id = 987654322');
        });
    }

    public function test_daily_command_syncs_each_client_with_its_bound_customer_and_skips_unconfigured_clients(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:50:00', 'Asia/Taipei'));
        $campaignsByCustomer = [
            '1111111111' => ['campaign_id' => '987654321', 'campaign_name' => 'North campaign', 'spend' => '111000000'],
            '2222222222' => ['campaign_id' => '987654322', 'campaign_name' => 'South campaign', 'spend' => '222000000'],
        ];
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) use ($campaignsByCustomer) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'temporary-access-token']);
            }

            if (preg_match('#/customers/(\d{10})/googleAds:searchStream$#', $request->url(), $matches) !== 1) {
                return Http::response([], 404);
            }

            $customerId = $matches[1];
            $campaign = $campaignsByCustomer[$customerId] ?? null;
            $query = $request->data()['query'] ?? '';

            if ($campaign !== null && str_starts_with($query, 'SELECT campaign.id, campaign.name, customer.id')) {
                return Http::response([[
                    'results' => [[
                        'customer' => ['id' => $customerId, 'currencyCode' => 'TWD'],
                        'campaign' => [
                            'id' => $campaign['campaign_id'],
                            'name' => $campaign['campaign_name'],
                        ],
                        'metrics' => [
                            'costMicros' => $campaign['spend'],
                            'impressions' => '1000',
                            'clicks' => '40',
                            'conversions' => '2',
                            'conversionsValue' => '200',
                        ],
                    ]],
                ]]);
            }

            return Http::response([]);
        });

        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $northClient = $user->clients()->create(['name' => 'North client']);
        $southClient = $user->clients()->create(['name' => 'South client']);
        $unconfiguredClient = $user->clients()->create(['name' => '待設定客戶']);

        foreach ([
            [$northClient, '1111111111', 'North Ads'],
            [$southClient, '2222222222', 'South Ads'],
        ] as [$client, $customerId, $customerName]) {
            GoogleAdsClientAccount::query()->create([
                'google_ads_connection_id' => $connection->getKey(),
                'client_id' => $client->getKey(),
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'currency_code' => 'TWD',
            ]);
        }

        $targetsByClient = [];
        foreach ([
            [$northClient, 'North campaign', '987654321'],
            [$southClient, 'South campaign', '987654322'],
            [$unconfiguredClient, 'Unconfigured campaign', '987654323'],
        ] as [$client, $campaignName, $campaignId]) {
            $target = SocialEngagementTarget::factory()->create([
                'client_id' => $client->getKey(),
                'tag' => $campaignName,
                'social_platform' => 'facebook',
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'google_ads_campaign_id' => $campaignId,
            ]);
            $target->forceFill(['user_id' => $user->getKey()])->save();
            $targetsByClient[$campaignName] = $target;
        }

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('完成 2 個活動，略過未設定帳戶 1 個，略過近期讀值 0 個，失敗 0 個。')
            ->assertExitCode(0);

        foreach ([
            ['North campaign', '1111111111', '111.0000'],
            ['South campaign', '2222222222', '222.0000'],
        ] as [$campaignName, $customerId, $spendAmount]) {
            $target = $targetsByClient[$campaignName];
            $this->assertDatabaseHas('social_engagement_target_google_ads_reports', [
                'google_ads_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
                'customer_id' => $customerId,
                'campaign_name' => $campaignName,
                'spend_amount' => $spendAmount,
            ]);
            $this->assertDatabaseHas('social_engagement_target_paid_ad_snapshots', [
                'source' => 'google_ads',
                'google_ads_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
                'account_id' => $customerId,
                'spend_amount' => $spendAmount,
            ]);
        }

        $this->assertDatabaseMissing('social_engagement_target_google_ads_reports', [
            'social_engagement_target_id' => $targetsByClient['Unconfigured campaign']->getKey(),
        ]);
        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 2);
        $this->assertSame('succeeded', $connection->fresh()->scheduled_sync_status);
        $this->assertSame(2, $connection->fresh()->scheduled_sync_synced_count);
        $this->assertSame(1, $connection->fresh()->scheduled_sync_skipped_count);
        $this->assertSame(0, $connection->fresh()->scheduled_sync_failed_count);

        foreach ([
            ['1111111111', '987654321'],
            ['2222222222', '987654322'],
        ] as [$customerId, $campaignId]) {
            Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/customers/'.$customerId.'/googleAds:searchStream')
                && str_starts_with($request->data()['query'] ?? '', 'SELECT campaign.id, campaign.name, customer.id')
                && str_contains($request->data()['query'], 'campaign.id = '.$campaignId));
        }

        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/customers/1111111111/')
            && str_contains($request->data()['query'] ?? '', 'campaign.id = 987654322'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/customers/2222222222/')
            && str_contains($request->data()['query'] ?? '', 'campaign.id = 987654321'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/customers/1234567890/'));
    }

    public function test_daily_command_skips_a_matching_snapshot_synced_within_twenty_four_hours(): void
    {
        $this->travelTo('2026-11-22 06:50:00');
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget('Current campaign', '987654321', '2026-11-01', '2026-11-30');
        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => '1234567890',
            'campaign_id' => '987654321',
            'campaign_name' => 'Current campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'clicks' => 40,
            'conversions' => '2.0000',
            'conversions_value' => '200.0000',
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

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('完成 0 個活動，略過未設定帳戶 0 個，略過近期讀值 1 個，失敗 0 個。')
            ->assertExitCode(0);

        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 1);
        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(0, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(1, $syncHealth->scheduled_sync_skipped_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        Http::assertNothingSent();
    }

    public function test_daily_sync_appends_a_paid_media_snapshot_after_the_previous_read_goes_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:50:00', 'Asia/Taipei'));
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignPayload('987654321', 'Active campaign', '100000000', 'TWD'))
                ->push([])
                ->push($this->conversionActionsPayload('987654321'))
                ->push([])
                ->push($this->campaignPayload('987654321', 'Active campaign', '125500000', 'TWD'))
                ->push([])
                ->push($this->conversionActionsPayload('987654321'))
                ->push([])
                ->push([])
                ->push([]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget('Active campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('google-ads:sync-due')->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 06:50:00', 'Asia/Taipei'));
        $this->artisan('google-ads:sync-due')->assertExitCode(0);

        $history = SocialEngagementTargetPaidAdSnapshot::query()
            ->where('social_engagement_target_id', $target->id)
            ->orderBy('synced_at')
            ->get();

        $this->assertCount(2, $history);
        $this->assertSame(['100.0000', '125.5000'], $history->pluck('spend_amount')->all());
        $this->assertSame('Purchase', $history[1]->conversion_actions[0]['conversion_action_name']);
        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 1);
        $this->assertDatabaseHas('social_engagement_target_google_ads_reports', [
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'spend_amount' => '125.5000',
        ]);
        Http::assertSentCount(10);
    }

    public function test_daily_command_does_not_request_google_data_without_a_connected_customer(): void
    {
        $this->travelTo('2026-11-22 06:50:00');
        $user = User::factory()->create();
        GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
        ]);
        $this->createTarget('Unconfigured campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('完成 0 個活動，略過未設定帳戶 1 個，略過近期讀值 0 個，失敗 0 個。')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_failed_google_ads_sync_does_not_store_a_report_or_trend_snapshot(): void
    {
        $this->travelTo('2026-11-22 06:50:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([
                'error' => ['message' => 'Access denied.'],
            ], 403),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget('Failed campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('social_engagement_target_google_ads_reports', [
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

    public function test_google_ads_sync_clears_provider_error_after_a_later_successful_run(): void
    {
        $this->travelTo('2026-11-22 06:50:00');
        Http::preventStrayRequests();
        $shouldFailCampaignRequest = true;
        Http::fake(function (ClientRequest $request) use (&$shouldFailCampaignRequest) {
            if (str_contains($request->url(), '/token')) {
                return Http::response(['access_token' => 'temporary-access-token']);
            }

            if (str_contains($request->url(), 'googleAds:searchStream')) {
                if ($shouldFailCampaignRequest) {
                    $shouldFailCampaignRequest = false;

                    return Http::response(['error' => ['message' => 'Access denied.']], 403);
                }

                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'campaign.advertising_channel_type')) {
                    return Http::response($this->campaignPayload('987654321', 'Recovered campaign', '125500000', 'TWD'));
                }

                if (str_contains($query, 'segments.conversion_action')) {
                    return Http::response($this->conversionActionsPayload('987654321'));
                }

                return Http::response([]);
            }

            return Http::response([], 404);
        });

        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget('Recovered campaign', '987654321', '2026-11-01', '2026-11-30');

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertSame('failed', $connection->fresh()->scheduled_sync_status);
        $this->assertNotEmpty($connection->fresh()->scheduled_sync_error);

        $this->travelTo('2026-11-23 06:50:00');

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('完成 1 個活動')
            ->assertExitCode(0);

        $syncHealth = $connection->fresh();
        $this->assertSame('succeeded', $syncHealth->scheduled_sync_status);
        $this->assertSame(1, $syncHealth->scheduled_sync_synced_count);
        $this->assertSame(0, $syncHealth->scheduled_sync_failed_count);
        $this->assertNull($syncHealth->scheduled_sync_error);
        $this->assertDatabaseHas('social_engagement_target_google_ads_reports', [
            'social_engagement_target_id' => $target->id,
            'spend_amount' => '125.5000',
        ]);
    }

    public function test_daily_command_records_an_actionable_error_when_google_ads_refresh_token_is_revoked(): void
    {
        $this->travelTo('2026-11-22 06:50:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget('Revoked token campaign', '987654326', '2026-11-01', '2026-11-30');
        $target->forceFill(['user_id' => $user->id])->save();

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('失敗 1 個')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('social_engagement_target_google_ads_reports', [
            'social_engagement_target_id' => $target->id,
        ]);
        $syncHealth = $connection->fresh();
        $this->assertSame('failed', $syncHealth->scheduled_sync_status);
        $this->assertSame(1, $syncHealth->scheduled_sync_failed_count);
        $this->assertSame('Google 授權憑證已失效或已撤銷，請重新連結帳戶。', $syncHealth->scheduled_sync_error);
        Http::assertSentCount(1);
    }

    private function createConnection(User $user): GoogleAdsConnection
    {
        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);

        $this->trackGoogleAdsFixtureConnection($connection);

        return $connection;
    }

    private function createTarget(string $tag, string $campaignId, string $startsOn, string $endsOn): SocialEngagementTarget
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => $tag,
            'social_platform' => 'facebook',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'google_ads_campaign_id' => $campaignId,
        ]);

        $this->bindGoogleAdsTargetFixture($target);

        return $target;
    }

    /** @return array{0: array{results: list<array<string, mixed>>}} */
    private function campaignPayload(string $campaignId, string $campaignName, string $costMicros, string $currencyCode): array
    {
        return [[
            'results' => [[
                'customer' => ['id' => '1234567890', 'currencyCode' => $currencyCode],
                'campaign' => ['id' => $campaignId, 'name' => $campaignName],
                'metrics' => [
                    'costMicros' => $costMicros,
                    'impressions' => '1200',
                    'clicks' => '48',
                    'conversions' => '5',
                    'conversionsValue' => '250',
                ],
            ]],
        ]];
    }

    /** @return array{0: array{results: list<array<string, mixed>>}} */
    private function conversionActionsPayload(string $campaignId): array
    {
        return [[
            'results' => [[
                'customer' => ['id' => '1234567890'],
                'campaign' => ['id' => $campaignId],
                'segments' => [
                    'conversionAction' => 'customers/1234567890/conversionActions/1001',
                    'conversionActionName' => 'Purchase',
                ],
                'metrics' => [
                    'conversions' => '5',
                    'conversionsValue' => '250',
                ],
            ]],
        ]];
    }
}
