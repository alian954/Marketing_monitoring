<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
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
        $target = $this->createTarget($client, 'Share Campaign');

        $response = $this->actingAs($user)
            ->post(route('clients.report-shares.store', $client), [
                'name' => '客戶週報',
                'expires_in_days' => '7',
                'target_ids' => [$target->id],
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
        $this->assertSame([$target->id], $share->campaign_target_ids);
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
        $target = $this->createTarget($client, 'Share Campaign');

        $this->actingAs($user)
            ->from(route('clients.report-shares.index', $client))
            ->post(route('clients.report-shares.store', $client), [
                'name' => 'Invalid expiration',
                'expires_in_days' => '365',
                'target_ids' => [$target->id],
            ])
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHasErrors(['expires_in_days' => '請選擇 7、30 或 90 天的有效期限。']);

        $this->assertDatabaseCount('client_report_shares', 0);
    }

    public function test_share_creation_form_only_lists_campaigns_for_the_selected_client(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $this->actingAs($owner);
        $campaignTarget = $this->createTarget($client, 'Client Campaign');

        $otherOwner = User::factory()->create();
        $otherClient = Client::factory()->for($otherOwner)->create();
        $this->actingAs($otherOwner);
        $this->createTarget($otherClient, 'Private Campaign');

        $this->actingAs($owner)
            ->get(route('clients.report-shares.index', $client))
            ->assertOk()
            ->assertSee('Client Campaign')
            ->assertSee('value="'.$campaignTarget->id.'"', false)
            ->assertSee('只會公開勾選活動的數據。')
            ->assertDontSee('Private Campaign');
    }

    public function test_public_client_report_only_includes_campaigns_selected_when_share_is_created(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $this->actingAs($owner);
        $selectedTarget = $this->createTarget($client, 'Selected Campaign');
        $hiddenTarget = $this->createTarget($client, 'Hidden Campaign');

        $response = $this->post(route('clients.report-shares.store', $client), [
            'name' => 'Selected campaign report',
            'expires_in_days' => '30',
            'target_ids' => [$selectedTarget->id],
        ])
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHas('createdShareUrl');

        $share = $client->reportShares()->firstOrFail();
        $this->assertSame([$selectedTarget->id], $share->campaign_target_ids);

        $shareUrl = $response->getSession()->get('createdShareUrl');
        $token = basename((string) parse_url((string) $shareUrl, PHP_URL_PATH));

        $this->actingAs(User::factory()->create())
            ->get(route('client-report-shares.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('Selected Campaign')
            ->assertDontSee('Hidden Campaign');
    }

    public function test_public_client_report_csv_export_respects_share_scope_and_sanitizes_formula_cells(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 12:00:00', 'UTC'));
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create(['reporting_currency_code' => 'TWD']);
        $this->actingAs($owner);
        $selectedTarget = $this->createTarget($client, '=Selected Campaign', [
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => '=Selected Campaign',
        ]);
        $this->createTarget($client, 'Hidden Campaign');
        $this->createGoogleAnalyticsReport($owner, $selectedTarget);
        $url = 'https://www.facebook.com/posts/shared-csv-selected-campaign';
        $monitor = Monitor::factory()->create([
            'client_id' => $client->id,
            'name' => 'Selected public post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $selectedTarget->tag,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'response_published_at' => now(),
            'response_public_engagement_metrics' => [
                'likes' => 12,
                'comments' => 3,
                'shares' => 1,
            ],
        ]);
        $token = str_repeat('8', 64);
        $this->createShare($client, $token, [$selectedTarget->id]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('client-report-shares.public.export', ['token' => $token]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename=marketing-report-2026-11-24.csv')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString("'=Selected Campaign", $content);
        $this->assertStringContainsString('公開互動', $content);
        $this->assertStringContainsString('報表幣別彙總', $content);
        $this->assertStringContainsString('456.78', $content);
        $this->assertStringContainsString('2026-11-24 20:00:00', $content);
        $this->assertStringContainsString('16', $content);
        $this->assertStringNotContainsString('Hidden Campaign', $content);
        $this->assertStringNotContainsString($token, $content);
    }

    public function test_share_creation_rejects_campaigns_from_another_client(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $this->actingAs($owner);
        $this->createTarget($client, 'Client Campaign');

        $otherOwner = User::factory()->create();
        $otherClient = Client::factory()->for($otherOwner)->create();
        $this->actingAs($otherOwner);
        $otherTarget = $this->createTarget($otherClient, 'Private Campaign');

        $this->actingAs($owner)
            ->from(route('clients.report-shares.index', $client))
            ->post(route('clients.report-shares.store', $client), [
                'name' => 'Invalid campaign scope',
                'expires_in_days' => '30',
                'target_ids' => [$otherTarget->id],
            ])
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHasErrors(['target_ids.0' => '所選活動不存在或不屬於此客戶。']);

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
            ->assertSee('2026/11/24 20:00')
            ->assertSee('<dd>321</dd>', false)
            ->assertSee('456.78 TWD')
            ->assertSee('尚無成功擷取')
            ->assertSee(route('client-report-shares.public.export', ['token' => $token]), false)
            ->assertDontSee('Other Client')
            ->assertDontSee('Private Campaign');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_public_client_report_shows_the_latest_capture_time_for_each_data_source(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 12:00:00', 'UTC'));
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $this->actingAs($owner);
        $target = $this->createTarget($client, 'Timestamped Campaign', [
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'Timestamped Campaign',
            'meta_campaign_id' => '777777777',
            'google_ads_campaign_id' => '888888888',
        ]);
        $this->createPublicEngagementPost($client, $target);
        $this->createGoogleAnalyticsReport($owner, $target);

        $metaConnection = MetaMarketingConnection::query()->create([
            'user_id' => $owner->id,
            'access_token' => 'meta-test-token',
            'ad_account_id' => '123456789',
            'currency_code' => 'TWD',
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '777777777',
            'campaign_name' => 'Timestamped Campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 40,
            'currency_code' => 'TWD',
            'synced_at' => now()->subMinutes(30),
        ]);

        $googleAdsConnection = GoogleAdsConnection::query()->create([
            'user_id' => $owner->id,
            'refresh_token' => 'google-ads-test-token',
            'customer_id' => '1234567890',
            'currency_code' => 'TWD',
        ]);
        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $googleAdsConnection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => '1234567890',
            'campaign_id' => '888888888',
            'campaign_name' => 'Timestamped Campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '200.0000',
            'impressions' => 2000,
            'clicks' => 80,
            'conversions' => '4.0000',
            'conversions_value' => '500.0000',
            'currency_code' => 'TWD',
            'synced_at' => now()->subMinutes(45),
        ]);

        $token = str_repeat('7', 64);
        $this->createShare($client, $token);

        $this->actingAs(User::factory()->create())
            ->get(route('client-report-shares.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('各資料來源最近擷取時間')
            ->assertSee('公開互動')
            ->assertSee('GA4')
            ->assertSee('Meta Ads')
            ->assertSee('Google Ads')
            ->assertSee('2026/11/24 20:00')
            ->assertSee('2026/11/24 19:30')
            ->assertSee('2026/11/24 19:15');
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
        $this->get(route('client-report-shares.public.export', ['token' => $token]))->assertNotFound();
    }

    public function test_revoked_client_report_share_returns_not_found(): void
    {
        $client = Client::factory()->create();
        $token = str_repeat('e', 64);
        $share = $this->createShare($client, $token);
        $share->forceFill(['revoked_at' => now()])->save();

        $this->get(route('client-report-shares.public', ['token' => $token]))->assertNotFound();
        $this->get(route('client-report-shares.public.export', ['token' => $token]))->assertNotFound();
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
            'attribution_mode' => $target->googleAnalyticsAttributionMode(),
            'campaign_id' => $target->google_ads_campaign_id,
            'source_name' => $target->googleAnalyticsSourceName(),
            'medium_name' => $target->googleAnalyticsMediumName(),
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

    /** @param  list<int>|null  $campaignTargetIds */
    private function createShare(Client $client, string $token, ?array $campaignTargetIds = null): ClientReportShare
    {
        $attributes = [
            'name' => 'Test report share',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
        ];

        if ($campaignTargetIds !== null) {
            $attributes['campaign_target_ids'] = $campaignTargetIds;
        }

        return $client->reportShares()->create($attributes);
    }
}
