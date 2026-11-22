<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialEngagementTargetMetaMarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_sync_requires_authentication(): void
    {
        $target = $this->createTarget();

        $this->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('login'));
    }

    public function test_meta_snapshot_is_scoped_to_the_current_users_connection(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($viewer);
        $otherConnection = $this->createConnection($otherUser);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $otherConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Private other-user campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '98765.4321',
            'impressions' => 1000000,
            'reach' => 800000,
            'clicks' => 40000,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('尚無 Meta Ads 讀值')
            ->assertDontSee('Private other-user campaign')
            ->assertDontSee('98,765.4321');

        $this->assertNull($response->viewData('metaMarketingReport'));
    }

    public function test_meta_sync_saves_campaign_insights_and_uses_meta_spend_for_efficiency(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Winter product launch',
                    'spend' => '250.75',
                    'impressions' => '12000',
                    'reach' => '9000',
                    'clicks' => '480',
                    'account_currency' => 'USD',
                ]],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget([
            'ad_spend_amount' => '999.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $connection = $this->createConnection($user);
        $gaConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'ga-refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $gaConnection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
            'source_name' => null,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 50,
            'engaged_sessions' => 40,
            'key_events' => '10.0000',
            'total_revenue' => '1003.0000',
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'Meta Ads 活動成效已同步。');

        $snapshot = SocialEngagementTargetMetaMarketingReport::query()->sole();
        $this->assertSame($connection->id, $snapshot->meta_marketing_connection_id);
        $this->assertSame('987654321', $snapshot->campaign_id);
        $this->assertSame('Winter product launch', $snapshot->campaign_name);
        $this->assertSame('250.7500', $snapshot->spend_amount);
        $this->assertSame(12000, $snapshot->impressions);
        $this->assertSame(9000, $snapshot->reach);
        $this->assertSame(480, $snapshot->clicks);
        $this->assertSame('USD', $snapshot->currency_code);
        $this->assertNotNull($snapshot->synced_at);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/act_123456789/insights')
                && $request->hasHeader('Authorization', 'Bearer authorized-token')
                && $query['level'] === 'campaign'
                && $query['fields'] === 'account_id,campaign_id,campaign_name,spend,impressions,reach,clicks,account_currency'
                && json_decode($query['filtering'], true)[0]['value'] === '987654321'
                && json_decode($query['time_range'], true) === ['since' => '2026-11-01', 'until' => '2026-11-30'];
        });

        $reportResponse = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 投放成效')
            ->assertSee('12,000')
            ->assertSee('9,000')
            ->assertSee('480')
            ->assertSee('250.7500 USD')
            ->assertSee('Meta Ads')
            ->assertSee('4.00x');

        $analysis = $reportResponse->viewData('adSpendAnalysis');
        $this->assertSame('meta_marketing', $analysis['ad_spend_source']);
        $this->assertSame(250.75, (float) $analysis['ad_spend_amount']);
        $this->assertSame(4.0, $analysis['roas']);
        $this->assertSame(5.015, $analysis['cost_per_session']);
        $this->assertSame(25.075, $analysis['cost_per_key_event']);
    }

    public function test_meta_sync_saves_zero_metrics_when_the_campaign_has_no_insights(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response(['data' => []]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status');

        $snapshot = SocialEngagementTargetMetaMarketingReport::query()->sole();
        $this->assertSame('0.0000', $snapshot->spend_amount);
        $this->assertSame(0, $snapshot->impressions);
        $this->assertSame(0, $snapshot->reach);
        $this->assertSame(0, $snapshot->clicks);
        $this->assertSame('TWD', $snapshot->currency_code);
    }

    public function test_meta_sync_reports_missing_connection_campaign_and_date_settings(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(['meta_campaign_id' => null]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->createConnection($user);

        $this->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 0);
    }

    public function test_meta_sync_requires_a_current_access_token(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);
        $connection->token_expires_at = now()->subMinute();
        $connection->save();

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 0);
        Http::assertNothingSent();
    }

    public function test_meta_api_errors_preserve_the_last_successful_snapshot(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'error' => ['code' => 613, 'message' => 'Call limit reached'],
            ], 429),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);
        $snapshot = SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Original campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 40,
            'currency_code' => 'TWD',
            'synced_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertSame(1000, $snapshot->fresh()->impressions);
        $this->assertSame('100.0000', $snapshot->fresh()->spend_amount);
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 1);
    }

    public function test_meta_insights_for_another_campaign_are_rejected_without_saving(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '111111111',
                    'campaign_name' => 'Unexpected campaign',
                    'spend' => '100',
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '40',
                    'account_currency' => 'TWD',
                ]],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 0);
    }

    public function test_target_form_saves_a_numeric_meta_campaign_id(): void
    {
        $this->createSocialMonitor('facebook', 'META-POST-1', 'Meta campaign');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Meta Ads 活動 ID');

        $this->post(route('social-engagement-targets.store'), [
            'tag' => 'Meta campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 500,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ])->assertRedirect(route('dashboard', [
            'engagement_platform' => 'facebook',
            'engagement_tag' => 'tag:Meta campaign',
        ]));

        $this->assertSame('987654321', SocialEngagementTarget::query()->sole()->meta_campaign_id);
    }

    public function test_target_form_rejects_a_non_numeric_meta_campaign_id(): void
    {
        $this->createSocialMonitor('facebook', 'META-POST-2', 'Meta campaign');

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Meta campaign',
                'social_platform' => 'facebook',
                'target_interactions' => 500,
                'meta_campaign_id' => 'campaign-123',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['meta_campaign_id']);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create(array_merge([
            'tag' => 'Winter campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ], $attributes));
    }

    private function createConnection(User $user): MetaMarketingConnection
    {
        return MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);
    }

    private function createSocialMonitor(string $platform, string $postId, string $tag): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/share/p/'.$postId.'/',
            'instagram' => 'https://www.instagram.com/reel/'.$postId.'/',
            'threads' => 'https://www.threads.com/share/'.$postId,
            default => throw new \InvalidArgumentException('Unsupported social platform.'),
        };

        return Monitor::factory()->create([
            'name' => ucfirst($platform).' '.$postId,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $tag,
        ]);
    }
}
