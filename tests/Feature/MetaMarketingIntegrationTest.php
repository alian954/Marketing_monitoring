<?php

namespace Tests\Feature;

use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_marketing_integration_requires_authentication(): void
    {
        $this->get(route('integrations.meta-marketing.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_integration_page_shows_account_settings_without_exposing_the_access_token(): void
    {
        config([
            'services.meta_marketing.client_id' => 'meta-client-id',
            'services.meta_marketing.client_secret' => 'meta-client-secret',
        ]);
        $user = User::factory()->create();
        MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'private-authorized-token',
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Demo Ads',
            'currency_code' => 'TWD',
            'token_expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->get(route('integrations.meta-marketing.edit'))
            ->assertOk()
            ->assertSee('Meta Ads')
            ->assertSee('Demo Ads')
            ->assertSee('123456789')
            ->assertSee('ads_read')
            ->assertDontSee('private-authorized-token');
    }

    public function test_connect_redirects_to_meta_with_ads_read_and_a_session_state(): void
    {
        config([
            'services.meta_marketing.client_id' => 'meta-client-id',
            'services.meta_marketing.client_secret' => 'meta-client-secret',
            'services.meta_marketing.redirect_uri' => 'https://monitor.example.test/integrations/meta-marketing/callback',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('integrations.meta-marketing.connect'))
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('https://www.facebook.com/v27.0/dialog/oauth', strtok($location, '?'));
        $this->assertSame('meta-client-id', $query['client_id']);
        $this->assertSame('ads_read', $query['scope']);
        $this->assertSame('https://monitor.example.test/integrations/meta-marketing/callback', $query['redirect_uri']);
        $this->assertSame($query['state'], session('meta_marketing.oauth_state'));
    }

    public function test_connect_reports_missing_server_oauth_configuration(): void
    {
        config([
            'services.meta_marketing.client_id' => null,
            'services.meta_marketing.client_secret' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('integrations.meta-marketing.edit'))
            ->post(route('integrations.meta-marketing.connect'))
            ->assertRedirect(route('integrations.meta-marketing.edit'))
            ->assertSessionHasErrors(['meta_marketing']);
    }

    public function test_oauth_callback_requires_the_matching_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('integrations.meta-marketing.callback', [
                'state' => 'untrusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.meta-marketing.edit'))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertDatabaseCount('meta_marketing_connections', 0);
    }

    public function test_oauth_callback_exchanges_and_encrypts_a_long_lived_token(): void
    {
        config([
            'services.meta_marketing.client_id' => 'meta-client-id',
            'services.meta_marketing.client_secret' => 'meta-client-secret',
            'services.meta_marketing.redirect_uri' => 'https://monitor.example.test/integrations/meta-marketing/callback',
        ]);
        Http::fake([
            'https://graph.facebook.com/v27.0/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'short-lived-token', 'expires_in' => 3600])
                ->push(['access_token' => 'long-lived-token', 'expires_in' => 5184000]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['meta_marketing.oauth_state' => 'trusted-state'])
            ->get(route('integrations.meta-marketing.callback', [
                'state' => 'trusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.meta-marketing.edit'))
            ->assertSessionHas('status');

        $connection = MetaMarketingConnection::query()->sole();
        $this->assertSame('long-lived-token', $connection->access_token);
        $this->assertTrue($connection->token_expires_at->isFuture());
        $this->assertDatabaseMissing('meta_marketing_connections', ['access_token' => 'long-lived-token']);
        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/oauth/access_token')
                && ($query['code'] ?? null) === 'authorization-code'
                && ($query['redirect_uri'] ?? null) === 'https://monitor.example.test/integrations/meta-marketing/callback';
        });
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/oauth/access_token')
                && ($query['grant_type'] ?? null) === 'fb_exchange_token'
                && ($query['fb_exchange_token'] ?? null) === 'short-lived-token';
        });
    }

    public function test_ad_account_id_is_normalized_checked_and_saved_for_the_current_user(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789*' => Http::response([
                'account_id' => '123456789',
                'name' => 'Demo Ads',
                'currency' => 'TWD',
            ]),
        ]);
        $user = User::factory()->create();
        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
        ]);

        $this->actingAs($user)
            ->put(route('integrations.meta-marketing.ad-account.update'), ['ad_account_id' => ' act_123456789 '])
            ->assertRedirect(route('integrations.meta-marketing.edit'))
            ->assertSessionHas('status', 'Meta 廣告帳戶已設定。');

        $this->assertSame('123456789', $connection->fresh()->ad_account_id);
        $this->assertSame('Demo Ads', $connection->fresh()->ad_account_name);
        $this->assertSame('TWD', $connection->fresh()->currency_code);
        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/act_123456789')
            && $request->hasHeader('Authorization', 'Bearer authorized-token'));
    }

    public function test_invalid_ad_account_id_is_rejected_with_a_user_message(): void
    {
        $user = User::factory()->create();
        MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
        ]);

        $this->actingAs($user)
            ->from(route('integrations.meta-marketing.edit'))
            ->put(route('integrations.meta-marketing.ad-account.update'), ['ad_account_id' => 'act_invalid'])
            ->assertRedirect(route('integrations.meta-marketing.edit'))
            ->assertSessionHasErrorsIn('metaMarketing', ['ad_account_id']);
    }

    public function test_disconnect_revokes_meta_access_and_removes_the_connection(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/me/permissions' => Http::response(['success' => true]),
        ]);
        $user = User::factory()->create();
        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
        ]);
        $target = SocialEngagementTarget::factory()->create([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Campaign to delete',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 40,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.meta-marketing.disconnect'))
            ->assertRedirect(route('integrations.meta-marketing.edit'))
            ->assertSessionHas('status', 'Meta Ads 已中斷連結。');

        $this->assertModelMissing($connection);
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 0);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.facebook.com/v27.0/me/permissions'
            && $request->hasHeader('Authorization', 'Bearer authorized-token'));
    }

    public function test_disconnect_keeps_the_connection_when_meta_cannot_confirm_revocation(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/me/permissions' => Http::response(['error' => ['message' => 'Unavailable']], 503),
        ]);
        $user = User::factory()->create();
        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.meta-marketing.disconnect'))
            ->assertRedirect(route('integrations.meta-marketing.edit'))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertModelExists($connection->fresh());
        $this->assertSame('authorized-token', $connection->fresh()->access_token);
    }
}
