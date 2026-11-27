<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_settings_require_authentication(): void
    {
        $this->get(route('integrations.google-analytics.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_integration_settings_explain_oauth_setup_and_ga4_metrics(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('integrations.google-analytics.edit'))
            ->assertOk()
            ->assertSee('用戶端 ID')
            ->assertSee('用戶端密鑰')
            ->assertSee('重新導向 URI')
            ->assertSee('utm_campaign')
            ->assertSee('重要事件與營收須先在 GA4 正確設定');
    }

    public function test_oauth_connection_explains_missing_server_credentials(): void
    {
        config([
            'services.google_analytics.client_id' => null,
            'services.google_analytics.client_secret' => null,
        ]);
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('integrations.google-analytics.connect'))
            ->assertRedirect(route('integrations.google-analytics.edit'))
            ->assertSessionHasErrors(['google_analytics']);

        Http::assertNothingSent();
    }

    public function test_oauth_start_uses_read_only_scope_and_session_state(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id.apps.googleusercontent.com',
            'services.google_analytics.client_secret' => 'client-secret',
            'services.google_analytics.redirect_uri' => 'https://monitor.example.test/integrations/google-analytics/callback',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('integrations.google-analytics.connect'))
            ->assertRedirect();
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));
        $this->assertSame('client-id.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('https://www.googleapis.com/auth/analytics.readonly', $query['scope']);
        $this->assertSame('https://monitor.example.test/integrations/google-analytics/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertIsString(session('google_analytics.oauth_state'));
        $this->assertSame(session('google_analytics.oauth_state'), $query['state']);
    }

    public function test_oauth_callback_rejects_an_invalid_state_without_calling_google(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->withSession(['google_analytics.oauth_state' => 'expected-state'])
            ->get(route('integrations.google-analytics.callback', [
                'state' => 'attacker-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-analytics.edit'))
            ->assertSessionHasErrors(['google_analytics']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('google_analytics_connections', 0);
    }

    public function test_oauth_callback_exchanges_code_and_encrypts_the_refresh_token(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
            'services.google_analytics.redirect_uri' => 'https://monitor.example.test/integrations/google-analytics/callback',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'short-lived-access-token',
                'refresh_token' => 'refresh-token-secret',
                'expires_in' => 3600,
            ]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['google_analytics.oauth_state' => 'trusted-state'])
            ->get(route('integrations.google-analytics.callback', [
                'state' => 'trusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-analytics.edit'))
            ->assertSessionHas('status', 'Google Analytics 已連結。');

        $connection = GoogleAnalyticsConnection::query()->sole();
        $encryptedToken = DB::table('google_analytics_connections')->where('id', $connection->id)->value('refresh_token');

        $this->assertSame($user->id, $connection->user_id);
        $this->assertSame('refresh-token-secret', $connection->refresh_token);
        $this->assertIsString($encryptedToken);
        $this->assertStringNotContainsString('refresh-token-secret', $encryptedToken);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->data()['grant_type'] === 'authorization_code'
            && $request->data()['code'] === 'authorization-code'
            && $request->data()['redirect_uri'] === 'https://monitor.example.test/integrations/google-analytics/callback');
    }

    public function test_oauth_callback_does_not_store_a_new_connection_without_a_refresh_token(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'short-lived-access-token']),
        ]);

        $this->actingAs(User::factory()->create())
            ->withSession(['google_analytics.oauth_state' => 'trusted-state'])
            ->get(route('integrations.google-analytics.callback', [
                'state' => 'trusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-analytics.edit'))
            ->assertSessionHasErrors(['google_analytics']);

        $this->assertDatabaseCount('google_analytics_connections', 0);
    }

    public function test_oauth_reconnection_preserves_an_existing_refresh_token_if_google_omits_a_new_one(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
            'services.google_analytics.redirect_uri' => 'https://monitor.example.test/integrations/google-analytics/callback',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access-token']),
        ]);
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'existing-refresh-token',
        ]);

        $this->actingAs($user)
            ->withSession(['google_analytics.oauth_state' => 'trusted-state'])
            ->get(route('integrations.google-analytics.callback', [
                'state' => 'trusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-analytics.edit'))
            ->assertSessionHas('status');

        $this->assertSame('existing-refresh-token', $connection->fresh()->refresh_token);
        $this->assertSame('123456789', $connection->fresh()->property_id);
        $this->assertDatabaseCount('google_analytics_connections', 1);
    }

    public function test_property_id_must_be_numeric_and_is_saved_for_the_connected_user(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'GA4 客戶']);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'refresh-token',
            'scheduled_sync_status' => 'partial',
            'scheduled_sync_started_at' => now()->subHour(),
            'scheduled_sync_finished_at' => now()->subMinutes(50),
            'scheduled_sync_synced_count' => 3,
            'scheduled_sync_skipped_count' => 1,
            'scheduled_sync_failed_count' => 1,
            'scheduled_sync_error' => '<script>alert(1)</script>',
        ]);
        $this->actingAs($user)
            ->from(route('integrations.google-analytics.edit'))
            ->put(route('integrations.google-analytics.property.update', $client), ['property_id' => 'properties/123', 'client_id' => $client->id])
            ->assertRedirect(route('integrations.google-analytics.edit'))
            ->assertSessionHasErrors(['property_id']);

        $this->actingAs($user)
            ->put(route('integrations.google-analytics.property.update', $client), ['property_id' => '123456789', 'client_id' => $client->id])
            ->assertRedirect(route('integrations.google-analytics.edit', ['client_id' => $client->id]))
            ->assertSessionHas('status');

        $this->assertNull($connection->fresh()->property_id);
        $this->assertDatabaseHas('google_analytics_client_properties', [
            'google_analytics_connection_id' => $connection->id,
            'client_id' => $client->id,
            'property_id' => '123456789',
        ]);
        $this->actingAs($user)
            ->get(route('integrations.google-analytics.edit'))
            ->assertOk()
            ->assertSee('GA4 資源 ID')
            ->assertSee('GA4 客戶')
            ->assertSee('123456789')
            ->assertSee('排程同步')
            ->assertSee('部分失敗')
            ->assertSee('更新 3 個活動 · 略過 1 個 · 失敗 1 個')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_property_settings_cannot_write_a_different_workspaces_client(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $client = $otherUser->clients()->create(['name' => '別的客戶']);
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->put(route('integrations.google-analytics.property.update', $client), [
                'client_id' => $client->id,
                'property_id' => '123456789',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('google_analytics_client_properties', 0);
    }

    public function test_integration_page_shows_the_start_time_for_a_running_sync(): void
    {
        $user = User::factory()->create();
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'refresh-token',
            'scheduled_sync_status' => 'running',
            'scheduled_sync_started_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($user)
            ->get(route('integrations.google-analytics.edit'))
            ->assertOk()
            ->assertSee('排程同步')
            ->assertSee('同步中')
            ->assertSee('開始時間')
            ->assertDontSee('最近執行');
    }

    public function test_disconnect_deletes_the_users_connection_and_private_report_snapshots(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);
        $user = User::factory()->create();
        $target = SocialEngagementTarget::factory()->create([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 10,
            'engaged_sessions' => 5,
            'key_events' => '1.0000',
            'total_revenue' => '20.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.google-analytics.disconnect'))
            ->assertRedirect(route('integrations.google-analytics.edit'))
            ->assertSessionHas('status', 'Google Analytics 已中斷連結。');

        $this->assertDatabaseCount('google_analytics_connections', 0);
        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 0);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request->data()['token'] === 'refresh-token');
    }

    public function test_disconnect_keeps_the_connection_if_google_cannot_revoke_the_token(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response(['error' => 'temporarily_unavailable'], 503),
        ]);
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.google-analytics.disconnect'))
            ->assertRedirect(route('integrations.google-analytics.edit'))
            ->assertSessionHasErrors(['google_analytics']);

        $this->assertModelExists($connection->fresh());
        $this->assertSame('refresh-token', $connection->fresh()->refresh_token);
    }
}
