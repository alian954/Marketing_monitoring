<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\GoogleAnalyticsConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientReportShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_hashed_share_link_with_a_seven_day_expiration(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 12:00:00', 'UTC'));
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->post(route('clients.report-shares.store', $client), [
                'name' => '客戶週報',
                'expires_in_days' => '7',
            ])
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHas('createdShareUrl');

        $shareUrl = $response->getSession()->get('createdShareUrl');
        $token = basename((string) parse_url((string) $shareUrl, PHP_URL_PATH));
        $share = $client->reportShares()->firstOrFail();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $share->token_hash);
        $this->assertNotSame($token, $share->token_hash);
        $this->assertSame('客戶週報', $share->name);
        $this->assertSame('2026-12-01 12:00:00', $share->expires_at->format('Y-m-d H:i:s'));

        $managementResponse = $this->get(route('clients.report-shares.index', $client))
            ->assertOk()
            ->assertSee((string) $shareUrl, false)
            ->assertSee('有效');
        $this->assertStringContainsString('no-store', (string) $managementResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $managementResponse->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $managementResponse->headers->get('Referrer-Policy'));

        $this->get(route('clients.report-shares.index', $client))
            ->assertOk()
            ->assertDontSee($token);
    }

    public function test_owner_cannot_create_a_share_with_an_unapproved_expiration_period(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('clients.report-shares.index', $client))
            ->post(route('clients.report-shares.store', $client), [
                'name' => 'Invalid expiration',
                'expires_in_days' => '365',
            ])
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHasErrors(['expires_in_days' => '請選擇 7、30 或 90 天的有效期限。']);

        $this->assertDatabaseCount('client_report_shares', 0);
    }

    public function test_client_list_links_to_report_share_management(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee(route('clients.report-shares.index', $client), false)
            ->assertSee('報表分享');
    }

    public function test_public_client_report_shows_current_client_marketing_data_only(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 12:00:00', 'UTC'));
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $this->actingAs($owner);
        $client = Client::factory()->for($owner)->create(['name' => 'Campaign Client']);
        $target = $this->createTarget($client, 'Public Campaign', [
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'Public Campaign',
        ]);
        $this->createPublicEngagementPost($client, $target);
        $this->createGoogleAnalyticsReport($owner, $target);

        $otherClient = Client::factory()->for($otherOwner)->create(['name' => 'Other Client']);
        $this->actingAs($otherOwner);
        $this->createTarget($otherClient, 'Private Campaign');

        $this->actingAs($owner);
        $token = str_repeat('b', 64);
        $this->createShare($client, $token);

        $response = $this->actingAs($otherOwner)
            ->get(route('client-report-shares.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('Campaign Client')
            ->assertSee('Public Campaign')
            ->assertSee('<dd>30</dd>', false)
            ->assertSee('1 / 1 篇取得完整互動數')
            ->assertSee('<dd>321</dd>', false)
            ->assertSee('456.78 TWD')
            ->assertDontSee('Other Client')
            ->assertDontSee('Private Campaign')
            ->assertDontSee($token);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_public_client_report_escapes_client_and_campaign_names(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create([
            'name' => '<script>window.alert(1)</script>',
        ]);
        $this->actingAs($user);
        $this->createTarget($client, '<img src=x onerror=alert(1)>');
        $token = str_repeat('c', 64);
        $this->createShare($client, $token);

        $this->get(route('client-report-shares.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('&lt;script&gt;window.alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<script>window.alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_expired_client_report_share_returns_not_found(): void
    {
        $client = Client::factory()->create();
        $token = str_repeat('d', 64);
        $share = $this->createShare($client, $token);
        $share->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->get(route('client-report-shares.public', ['token' => $token]))->assertNotFound();
    }

    public function test_revoked_client_report_share_returns_not_found(): void
    {
        $client = Client::factory()->create();
        $token = str_repeat('e', 64);
        $share = $this->createShare($client, $token);
        $share->forceFill(['revoked_at' => now()])->save();

        $this->get(route('client-report-shares.public', ['token' => $token]))->assertNotFound();
    }

    public function test_malformed_public_report_token_returns_not_found(): void
    {
        $this->get('/shared/client-reports/not-a-token')->assertNotFound();
    }

    public function test_guest_cannot_open_client_report_share_management(): void
    {
        $client = Client::factory()->create();

        $this->get(route('clients.report-shares.index', $client))
            ->assertRedirect(route('login'));
    }

    public function test_share_management_cannot_read_or_revoke_another_accounts_link(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $otherClient = Client::factory()->for($otherOwner)->create();
        $share = $this->createShare($client, str_repeat('f', 64));

        $this->actingAs($otherOwner)
            ->get(route('clients.report-shares.index', $client))
            ->assertNotFound();

        $this->delete(route('clients.report-shares.destroy', [$otherClient, $share->id]))
            ->assertNotFound();

        $this->assertNull($share->fresh()->revoked_at);
    }

    public function test_owner_can_revoke_a_share_and_disable_its_public_report(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $token = str_repeat('9', 64);
        $share = $this->createShare($client, $token);

        $this->actingAs($user)
            ->delete(route('clients.report-shares.destroy', [$client, $share->id]))
            ->assertRedirect(route('clients.report-shares.index', $client));

        $this->assertNotNull($share->fresh()->revoked_at);
        $this->get(route('client-report-shares.public', ['token' => $token]))->assertNotFound();
    }

    public function test_deleting_a_client_revokes_access_to_its_report_shares(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $token = str_repeat('a', 64);
        $share = $this->createShare($client, $token);

        $this->actingAs($user)
            ->delete(route('clients.destroy', $client))
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseMissing('client_report_shares', ['id' => $share->id]);
        $this->get(route('client-report-shares.public', ['token' => $token]))->assertNotFound();
    }

    private function createTarget(Client $client, string $tag, array $overrides = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create(array_replace([
            'client_id' => $client->id,
            'tag' => $tag,
            'social_platform' => 'facebook',
        ], $overrides));
    }

    private function createPublicEngagementPost(Client $client, SocialEngagementTarget $target): Monitor
    {
        $url = 'https://www.facebook.com/posts/client-report-public-campaign';
        $monitor = Monitor::factory()->create([
            'client_id' => $client->id,
            'name' => 'Client report public post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
        ]);

        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'response_published_at' => now(),
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 23,
                'comments' => 5,
                'shares' => 2,
            ],
        ]);

        return $monitor;
    }

    private function createGoogleAnalyticsReport(User $user, SocialEngagementTarget $target): void
    {
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'ga-refresh-token',
        ]);

        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => 'manual_utm',
            'campaign_id' => null,
            'source_name' => null,
            'medium_name' => null,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'sessions' => 321,
            'engaged_sessions' => 250,
            'key_events' => '5.0000',
            'total_revenue' => '456.7800',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
    }

    private function createShare(Client $client, string $token): ClientReportShare
    {
        return $client->reportShares()->create([
            'name' => 'Test report share',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
        ]);
    }
}
