<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAdsClientAccount;
use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_ads_integration_requires_authentication(): void
    {
        $this->get(route('integrations.google-ads.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_integration_page_shows_connection_settings_without_exposing_the_refresh_token(): void
    {
        config([
            'services.google_ads.client_id' => 'google-client-id',
            'services.google_ads.client_secret' => 'google-client-secret',
        ]);
        $user = User::factory()->create();
        GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'private-refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Demo Ads',
            'currency_code' => 'TWD',
            'scheduled_sync_status' => 'succeeded',
            'scheduled_sync_started_at' => now()->subHour(),
            'scheduled_sync_finished_at' => now()->subMinutes(50),
            'scheduled_sync_synced_count' => 4,
            'scheduled_sync_skipped_count' => 2,
            'scheduled_sync_failed_count' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('integrations.google-ads.edit'))
            ->assertOk()
            ->assertSee('Google Ads')
            ->assertSee('Demo Ads')
            ->assertSee('1234567890')
            ->assertSee('每個客戶需設定自己的 10 位 Google Ads 客戶 ID')
            ->assertSee('本應用只讀取廣告報表與客戶資料')
            ->assertSee('正式客戶資料需先取得 Google Ads API production access')
            ->assertSee('排程同步')
            ->assertSee('同步正常')
            ->assertSee('更新 4 個活動 · 略過 2 個 · 失敗 0 個')
            ->assertDontSee('private-refresh-token');
    }

    public function test_connect_redirects_to_google_with_ads_scope_and_session_state(): void
    {
        config([
            'services.google_ads.client_id' => 'google-client-id',
            'services.google_ads.client_secret' => 'google-client-secret',
            'services.google_ads.redirect_uri' => 'https://monitor.example.test/integrations/google-ads/callback',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('integrations.google-ads.connect'))
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', strtok($location, '?'));
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('https://www.googleapis.com/auth/adwords', $query['scope']);
        $this->assertSame('https://monitor.example.test/integrations/google-ads/callback', $query['redirect_uri']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame($query['state'], session('google_ads.oauth_state'));
    }

    public function test_connect_reports_missing_oauth_configuration(): void
    {
        config([
            'services.google_ads.client_id' => null,
            'services.google_ads.client_secret' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('integrations.google-ads.edit'))
            ->post(route('integrations.google-ads.connect'))
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHasErrors(['google_ads']);
    }

    public function test_oauth_callback_rejects_a_state_that_does_not_match_the_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['google_ads.oauth_state' => 'expected-state'])
            ->get(route('integrations.google-ads.callback', [
                'state' => 'untrusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHasErrors(['google_ads']);

        $this->assertDatabaseCount('google_ads_connections', 0);
        Http::assertNothingSent();
    }

    public function test_oauth_callback_saves_an_encrypted_refresh_token(): void
    {
        config([
            'services.google_ads.client_id' => 'google-client-id',
            'services.google_ads.client_secret' => 'google-client-secret',
            'services.google_ads.redirect_uri' => 'https://monitor.example.test/integrations/google-ads/callback',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'temporary-access-token',
                'refresh_token' => 'private-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['google_ads.oauth_state' => 'trusted-state'])
            ->get(route('integrations.google-ads.callback', [
                'state' => 'trusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHas('status');

        $connection = GoogleAdsConnection::query()->sole();
        $this->assertSame('private-refresh-token', $connection->refresh_token);
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        $this->assertDatabaseMissing('google_ads_connections', ['refresh_token' => 'private-refresh-token']);
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && ($request->data()['code'] ?? null) === 'authorization-code'
                && ($request->data()['grant_type'] ?? null) === 'authorization_code'
                && ($request->data()['redirect_uri'] ?? null) === 'https://monitor.example.test/integrations/google-ads/callback';
        });
    }

    public function test_oauth_reconnection_preserves_the_existing_refresh_token_when_google_omits_one(): void
    {
        config([
            'services.google_ads.client_id' => 'google-client-id',
            'services.google_ads.client_secret' => 'google-client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
        ]);
        $user = User::factory()->create();
        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'existing-refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Existing customer',
            'currency_code' => 'USD',
        ]);

        $this->actingAs($user)
            ->withSession(['google_ads.oauth_state' => 'trusted-state'])
            ->get(route('integrations.google-ads.callback', [
                'state' => 'trusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHas('status');

        $this->assertSame('existing-refresh-token', $connection->fresh()->refresh_token);
        $this->assertSame('1234567890', $connection->fresh()->customer_id);
        $this->assertDatabaseCount('google_ads_connections', 1);
    }

    public function test_oauth_callback_does_not_create_a_connection_without_a_refresh_token(): void
    {
        config([
            'services.google_ads.client_id' => 'google-client-id',
            'services.google_ads.client_secret' => 'google-client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
        ]);

        $this->actingAs(User::factory()->create())
            ->withSession(['google_ads.oauth_state' => 'trusted-state'])
            ->get(route('integrations.google-ads.callback', [
                'state' => 'trusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHasErrors(['google_ads']);

        $this->assertDatabaseCount('google_ads_connections', 0);
    }

    public function test_customer_id_is_normalized_verified_and_saved_for_the_current_user(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:search' => Http::response([
                'results' => [[
                    'customer' => [
                        'id' => '1234567890',
                        'descriptiveName' => 'Demo Ads',
                        'currencyCode' => 'TWD',
                    ],
                ]],
            ]),
        ]);
        $user = User::factory()->create();
        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
        ]);
        $client = Client::ensureDefaultFor($user);

        $this->actingAs($user)
            ->put(route('integrations.google-ads.clients.customer.update', $client), [
                'customer_id' => ' 123-456-7890 ',
                'login_customer_id' => '098-765-4321',
            ])
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHas('status', $client->name.'的 Google Ads 客戶帳戶已驗證並儲存。');

        $account = GoogleAdsClientAccount::query()->sole();
        $this->assertSame((int) $connection->getKey(), (int) $account->google_ads_connection_id);
        $this->assertSame((int) $client->getKey(), (int) $account->client_id);
        $this->assertSame('1234567890', $account->customer_id);
        $this->assertSame('0987654321', $account->login_customer_id);
        $this->assertSame('Demo Ads', $account->customer_name);
        $this->assertSame('TWD', $account->currency_code);
        $this->assertNull($connection->fresh()->customer_id);
        Http::assertSent(function (ClientRequest $request): bool {
            return str_ends_with($request->url(), '/customers/1234567890/googleAds:search')
                && $request->hasHeader('Authorization', 'Bearer temporary-access-token')
                && $request->hasHeader('login-customer-id', '0987654321')
                && str_contains($request->data()['query'], 'customer.descriptive_name');
        });
    }

    public function test_invalid_customer_id_is_rejected_with_a_user_message_before_google_is_called(): void
    {
        $user = User::factory()->create();
        GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
        ]);
        $client = Client::ensureDefaultFor($user);

        $this->actingAs($user)
            ->from(route('integrations.google-ads.edit'))
            ->put(route('integrations.google-ads.clients.customer.update', $client), [
                'customer_id' => 'invalid-customer',
            ])
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHasErrorsIn('googleAds', ['customer_id']);

        Http::assertNothingSent();
    }

    public function test_disconnect_revokes_google_access_and_removes_saved_reports(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);
        $user = User::factory()->create();
        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
            'customer_id' => '1234567890',
            'currency_code' => 'TWD',
        ]);
        $target = SocialEngagementTarget::factory()->create([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => '1234567890',
            'campaign_id' => '987654321',
            'campaign_name' => 'Campaign to delete',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'clicks' => 40,
            'conversions' => '2.0000',
            'conversions_value' => '200.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.google-ads.disconnect'))
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHas('status', 'Google Ads 已中斷連結。');

        $this->assertModelMissing($connection);
        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 0);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://oauth2.googleapis.com/revoke'
            && ($request->data()['token'] ?? null) === 'authorized-refresh-token');
    }

    public function test_disconnect_keeps_the_connection_when_google_cannot_confirm_revocation(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response(['error' => 'temporarily_unavailable'], 503),
        ]);
        $user = User::factory()->create();
        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.google-ads.disconnect'))
            ->assertRedirect(route('integrations.google-ads.edit'))
            ->assertSessionHasErrors(['google_ads']);

        $this->assertModelExists($connection->fresh());
        $this->assertSame('authorized-refresh-token', $connection->fresh()->refresh_token);
    }
}
