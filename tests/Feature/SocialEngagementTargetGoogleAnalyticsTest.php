<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialEngagementTargetGoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ga4_sync_requires_authentication_and_does_not_make_an_api_call(): void
    {
        $target = $this->createTarget();
        Http::fake();

        $this->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_ga4_sync_requires_a_connected_account_property_and_campaign_period(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $target = $this->createTarget();

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'encrypted-refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        $connection = $user->googleAnalyticsConnection()->firstOrFail();
        $connection->property_id = '123456789';
        $connection->save();

        $target->update(['starts_on' => null, 'ends_on' => null]);
        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 0);
    }

    public function test_ga4_sync_queries_campaign_metrics_and_renders_the_private_snapshot(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'short-lived-access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::response([
                'rows' => [[
                    'dimensionValues' => [['value' => 'spring_launch_2026']],
                    'metricValues' => [
                        ['value' => '2400'],
                        ['value' => '1800'],
                        ['value' => '37.5'],
                        ['value' => '12890.25'],
                    ],
                ]],
                'metadata' => ['currencyCode' => 'TWD'],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget([
            'tag' => 'Spring launch posts',
            'ga4_campaign_name' => 'spring_launch_2026',
            'ga4_source_name' => 'facebook_ads',
        ]);
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'GA4 活動成效已同步。');

        $snapshot = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertSame('spring_launch_2026', $snapshot->campaign_name);
        $this->assertSame('facebook_ads', $snapshot->source_name);
        $this->assertSame(2400, $snapshot->sessions);
        $this->assertSame(1800, $snapshot->engaged_sessions);
        $this->assertSame('37.5000', $snapshot->key_events);
        $this->assertSame('12890.2500', $snapshot->total_revenue);
        $this->assertSame('TWD', $snapshot->currency_code);
        $this->assertNotNull($snapshot->synced_at);

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dateRanges'] === [[
                'startDate' => '2026-11-01',
                'endDate' => '2026-11-30',
            ]]
            && $request->data()['dimensions'] === [['name' => 'sessionManualCampaignName']]
            && $request->data()['metrics'] === [
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'keyEvents'],
                ['name' => 'totalRevenue'],
            ]
            && $request->data()['dimensionFilter']['andGroup']['expressions'][0]['filter']['stringFilter']['value'] === 'spring_launch_2026'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['fieldName'] === 'sessionManualSource'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['stringFilter']['value'] === 'facebook_ads'
            && $request->hasHeader('Authorization', 'Bearer short-lived-access-token'));

        $reportResponse = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 導流成效')
            ->assertSee('2,400')
            ->assertSee('1,800')
            ->assertSee('37.50')
            ->assertSee('12,890.25')
            ->assertSee('TWD')
            ->assertSee('spring_launch_2026')
            ->assertSee('facebook_ads');

        $this->assertNull($reportResponse->viewData('report')['interaction_total']);

        Http::assertSentCount(2);
    }

    public function test_ga4_sync_uses_the_social_target_tag_when_no_utm_campaign_name_is_set(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::response([
                'rows' => [],
                'metadata' => ['currencyCode' => 'JPY'],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget(['tag' => 'Autumn promotion']);
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status');

        $snapshot = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertSame('Autumn promotion', $snapshot->campaign_name);
        $this->assertSame(0, $snapshot->sessions);
        $this->assertSame(0, $snapshot->engaged_sessions);
        $this->assertSame('0.0000', $snapshot->key_events);
        $this->assertSame('0.0000', $snapshot->total_revenue);
        $this->assertSame('JPY', $snapshot->currency_code);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensionFilter']['filter']['stringFilter']['value'] === 'Autumn promotion');
    }

    public function test_ga4_quota_error_keeps_the_last_successful_snapshot(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::response([
                'error' => ['message' => 'RESOURCE_EXHAUSTED'],
            ], 429),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        $snapshot = SocialEngagementTargetGoogleAnalyticsReport::query()->create([
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
            'synced_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        $this->assertSame(12, $snapshot->fresh()->sessions);
        $this->assertSame('500.0000', $snapshot->fresh()->total_revenue);
        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 1);
    }

    public function test_ga4_thresholded_data_is_not_saved_as_zero(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::response([
                'rows' => [],
                'metadata' => [
                    'currencyCode' => 'TWD',
                    'subjectToThresholding' => true,
                ],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 0);
    }

    public function test_ga4_report_snapshot_is_scoped_to_the_current_users_connection(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $target = $this->createTarget();
        $viewerConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $viewer->id,
            'property_id' => '123456789',
            'refresh_token' => 'viewers-token',
        ]);
        $otherConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $otherUser->id,
            'property_id' => '987654321',
            'refresh_token' => 'other-users-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $otherConnection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 987654321,
            'engaged_sessions' => 987654320,
            'key_events' => '99.0000',
            'total_revenue' => '1000000.0000',
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('尚無 GA4 讀值');

        $this->assertNull($response->viewData('googleAnalyticsReport'));
        $this->assertStringNotContainsString('987,654,321', $response->getContent());
        $this->assertStringNotContainsString('1,000,000.00', $response->getContent());
    }

    /** @param array<string, mixed> $attributes */
    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($attributes + [
            'tag' => 'Spring launch',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
    }
}
