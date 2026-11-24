<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAdsClientAccount;
use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Services\GoogleAdsCampaignReportSync;
use App\Services\GoogleAdsClientAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsClientAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_legacy_customer_is_left_pending_for_each_client(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user, [
            'customer_id' => '1234567890',
            'customer_name' => 'Legacy Ads',
            'currency_code' => 'TWD',
        ]);
        $firstClient = $this->createClient($user, '北區客戶');
        $secondClient = $this->createClient($user, '南區客戶');

        $response = $this->actingAs($user)
            ->get(route('integrations.google-ads.edit'))
            ->assertOk()
            ->assertSee('北區客戶')
            ->assertSee('南區客戶')
            ->assertSee('待設定客戶帳戶。');

        $this->assertStringContainsString('待設定客戶帳戶。', $response->getContent());
        $this->assertNull(app(GoogleAdsClientAccountResolver::class)->forClient($connection, $firstClient));
        $this->assertNull(app(GoogleAdsClientAccountResolver::class)->forClient($connection, $secondClient));
        $this->assertDatabaseCount('google_ads_client_accounts', 0);
        $this->assertStringNotContainsString('Legacy Ads', $response->getContent());
    }

    public function test_campaign_options_use_the_account_bound_to_the_target_client(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/2222222222/googleAds:searchStream' => Http::response([[
                'results' => [[
                    'customer' => ['id' => '2222222222'],
                    'campaign' => [
                        'id' => '987654321',
                        'name' => 'South client campaign',
                        'status' => 'ENABLED',
                        'advertisingChannelType' => 'SEARCH',
                    ],
                ]],
            ]]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $firstClient = $this->createClient($user, 'North client');
        $secondClient = $this->createClient($user, 'South client');
        $this->createAccount($connection, $firstClient, '1111111111', 'North Ads');
        $this->createAccount($connection, $secondClient, '2222222222', 'South Ads', '3333333333');
        $target = $this->createTarget($user, $secondClient, '987654321');

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.campaign-options', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('google_ads_campaign_options');

        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request): bool {
            return str_ends_with($request->url(), '/customers/2222222222/googleAds:searchStream')
                && $request->hasHeader('login-customer-id', '3333333333')
                && str_contains($request->data()['query'] ?? '', 'campaign.advertising_channel_type');
        });
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/customers/1111111111/'));
    }

    public function test_campaign_sync_reads_and_saves_metrics_from_the_bound_client_account(): void
    {
        Http::fake(function (ClientRequest $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'campaign.name')) {
                return Http::response([[
                    'results' => [[
                        'customer' => ['id' => '2222222222', 'currencyCode' => 'USD'],
                        'campaign' => [
                            'id' => '987654321',
                            'name' => 'South paid campaign',
                            'advertisingChannelType' => 'SEARCH',
                        ],
                        'metrics' => [
                            'costMicros' => '123000000',
                            'impressions' => '1000',
                            'clicks' => '30',
                            'conversions' => '3',
                            'conversionsValue' => '400',
                        ],
                    ]],
                ]]);
            }

            return Http::response([]);
        });
        $user = User::factory()->create();
        $connection = $this->createConnection($user, [
            'customer_id' => '1111111111',
            'customer_name' => 'Legacy account',
            'currency_code' => 'TWD',
        ]);
        $firstClient = $this->createClient($user, 'North client');
        $secondClient = $this->createClient($user, 'South client');
        $this->createAccount($connection, $firstClient, '1111111111', 'North Ads');
        $this->createAccount($connection, $secondClient, '2222222222', 'South Ads', '3333333333');
        $target = $this->createTarget($user, $secondClient, '987654321');

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('2222222222', $report->customer_id);
        $this->assertSame('South paid campaign', $report->campaign_name);
        $this->assertSame('123.0000', $report->spend_amount);
        $this->assertDatabaseHas('social_engagement_target_paid_ad_snapshots', [
            'google_ads_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'account_id' => '2222222222',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/customers/2222222222/googleAds:searchStream')
            && $request->hasHeader('login-customer-id', '3333333333'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/customers/1111111111/'));
    }

    public function test_google_ads_customer_can_be_explicitly_reused_for_another_client(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:search' => Http::response([
                'results' => [[
                    'customer' => [
                        'id' => '1234567890',
                        'descriptiveName' => 'Shared Ads',
                        'currencyCode' => 'TWD',
                    ],
                ]],
            ]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $firstClient = $this->createClient($user, 'First client');
        $secondClient = $this->createClient($user, 'Second client');
        $this->createAccount($connection, $firstClient, '1234567890', 'Shared Ads');

        $this->actingAs($user)
            ->put(route('integrations.google-ads.clients.customer.update', $secondClient), [
                'customer_id' => '1234567890',
            ])
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHas('status', 'Second client的 Google Ads 客戶帳戶已驗證並儲存。');

        $this->assertDatabaseCount('google_ads_client_accounts', 2);
        $this->assertDatabaseHas('google_ads_client_accounts', [
            'client_id' => $firstClient->getKey(),
            'customer_id' => '1234567890',
        ]);
        $this->assertDatabaseHas('google_ads_client_accounts', [
            'client_id' => $secondClient->getKey(),
            'customer_id' => '1234567890',
        ]);
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/googleAds:search'));
    }

    public function test_client_account_settings_cannot_be_changed_from_another_workspace(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $connection = $this->createConnection($user);
        $foreignClient = $this->createClient($otherUser, 'Private client');

        $this->actingAs($user)
            ->put(route('integrations.google-ads.clients.customer.update', $foreignClient), [
                'customer_id' => '1234567890',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('google_ads_client_accounts', 0);
        Http::assertNothingSent();
        $this->assertModelExists($connection);
    }

    public function test_reports_and_csv_do_not_show_another_customer_account(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $northClient = $this->createClient($user, 'North client');
        $client = $this->createClient($user, 'South client');
        $this->createAccount($connection, $northClient, '1111111111', 'North Ads');
        $this->createAccount($connection, $client, '2222222222', 'South Ads');
        $target = $this->createTarget($user, $client, '987654321');
        $this->createReport($connection, $target, '1111111111', [
            'campaign_name' => 'Private North campaign',
            'spend_amount' => '98765.4321',
            'search_terms_data' => ['status' => 'complete', 'rows' => []],
            'search_terms_expires_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertDontSee('Private North campaign')
            ->assertDontSee('98,765.4321');

        $this->assertNull($response->viewData('googleAdsReport'));
        $campaignCsv = $this->get(route('social-engagement-targets.report.export', $target))
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('Private North campaign', $campaignCsv);
        $this->assertStringNotContainsString('98,765.4321', $campaignCsv);
        $this->get(route('social-engagement-targets.google-ads.search-terms', $target))
            ->assertNotFound();
    }

    private function createConnection(User $user, array $attributes = []): GoogleAdsConnection
    {
        return GoogleAdsConnection::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'refresh_token' => 'authorized-refresh-token',
        ], $attributes));
    }

    private function createClient(User $user, string $name): Client
    {
        return $user->clients()->create(['name' => $name]);
    }

    private function createAccount(
        GoogleAdsConnection $connection,
        Client $client,
        string $customerId,
        string $customerName,
        ?string $loginCustomerId = null,
    ): GoogleAdsClientAccount {
        return $connection->clientAccounts()->create([
            'client_id' => $client->getKey(),
            'customer_id' => $customerId,
            'login_customer_id' => $loginCustomerId,
            'customer_name' => $customerName,
            'currency_code' => 'TWD',
        ]);
    }

    private function createTarget(User $user, Client $client, string $campaignId): SocialEngagementTarget
    {
        $target = $client->socialEngagementTargets()->create([
            'tag' => 'Client campaign '.$campaignId,
            'social_platform' => 'facebook',
            'target_interactions' => 0,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => $campaignId,
        ]);

        $target->forceFill(['user_id' => $user->getKey()])->save();

        return $target;
    }

    private function createReport(
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
        string $customerId,
        array $attributes = [],
    ): SocialEngagementTargetGoogleAdsReport {
        return SocialEngagementTargetGoogleAdsReport::query()->create(array_merge([
            'google_ads_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'customer_id' => $customerId,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Campaign from other account',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '250.0000',
            'impressions' => 1000,
            'clicks' => 40,
            'conversions' => '2.0000',
            'conversions_value' => '500.0000',
            'currency_code' => 'TWD',
            'daily_metrics' => ['status' => 'complete', 'rows' => []],
            'synced_at' => now(),
        ], $attributes));
    }
}
