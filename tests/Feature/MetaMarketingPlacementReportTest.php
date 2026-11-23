<?php

namespace Tests\Feature;

use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingCampaignReportSync;
use App\Services\MetaMarketingDataApi;
use App\Services\MetaMarketingPlacementPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingPlacementReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_placement_insights_requests_platform_and_position_breakdowns(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [$this->placementInsightRow([
                    'spend' => '50.25',
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '50',
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '3.5']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '200.75']],
                ])],
            ]),
        ]);

        $result = (new MetaMarketingDataApi)->campaignPlacementInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );

        $this->assertSame('50.2500', $result['rows'][0]['spend_amount']);
        $this->assertSame('facebook', $result['rows'][0]['publisher_platform']);
        $this->assertSame('feed', $result['rows'][0]['platform_position']);
        $this->assertSame(800, $result['rows'][0]['reach']);
        $this->assertSame([['action_type' => 'omni_purchase', 'value' => '3.5000']], $result['rows'][0]['actions']);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->hasHeader('Authorization', 'Bearer access-token')
                && $query['level'] === 'campaign'
                && $query['breakdowns'] === 'publisher_platform,platform_position'
                && $query['limit'] === '100'
                && $query['fields'] === 'account_id,campaign_id,publisher_platform,platform_position,spend,impressions,reach,clicks,actions,action_values,account_currency'
                && json_decode($query['filtering'], true)[0]['value'] === '987654321'
                && json_decode($query['time_range'], true) === ['since' => '2026-11-01', 'until' => '2026-11-30'];
        });
        Http::assertSentCount(1);
    }

    public function test_campaign_placement_insights_rejects_rows_outside_the_requested_campaign(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [$this->placementInsightRow(['campaign_id' => '111111111'])],
            ]),
        ]);

        $this->expectException(MetaMarketingApiException::class);

        (new MetaMarketingDataApi)->campaignPlacementInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    public function test_placement_report_derives_metrics_sorts_rows_and_marks_expired_data(): void
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-30',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [
                $this->placementInsightRow([
                    'publisher_platform' => 'facebook',
                    'platform_position' => 'feed',
                    'spend_amount' => '60.0000',
                    'spend' => '60',
                    'impressions' => 2000,
                    'clicks' => 20,
                    'reach' => null,
                ]),
                $this->placementInsightRow([
                    'publisher_platform' => 'instagram',
                    'platform_position' => 'reels',
                    'spend_amount' => '25.0000',
                    'spend' => '25',
                    'impressions' => 1000,
                    'clicks' => 50,
                    'reach' => 800,
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '2']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '250']],
                ]),
            ],
        ];

        $report = (new MetaMarketingPlacementPerformanceReport)->build($snapshot, CarbonImmutable::now()->addDay(), 'clicks');

        $this->assertSame('complete', $report['status']);
        $this->assertSame('instagram', $report['rows'][0]['publisher_platform']);
        $this->assertSame(5.0, $report['rows'][0]['ctr_percent']);
        $this->assertSame(0.5, $report['rows'][0]['cost_per_click']);
        $this->assertSame(25.0, $report['rows'][0]['cost_per_thousand_impressions']);
        $this->assertSame('2', $report['rows'][0]['purchases']);
        $this->assertSame('250', $report['rows'][0]['purchase_value']);
        $this->assertNull($report['rows'][1]['reach']);
        $this->assertSame('expired', (new MetaMarketingPlacementPerformanceReport)->build(
            $snapshot,
            CarbonImmutable::now()->subSecond(),
        )['status']);
    }

    public function test_placement_api_failure_does_not_block_campaign_or_ad_reports(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push(['data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Winter launch',
                    'spend' => '250.75',
                    'impressions' => '12000',
                    'reach' => '9000',
                    'clicks' => '480',
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['error' => ['code' => 200, 'message' => 'Permission denied']], 400)
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(MetaMarketingCampaignReportSync::class)->sync($connection, $target);

        $this->assertSame('250.7500', $report->spend_amount);
        $this->assertSame('complete', $report->ad_insights['status']);
        $this->assertSame('unavailable', $report->placement_insights['status']);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 1);
        Http::assertSentCount(6);
    }

    public function test_successful_placement_insights_are_encrypted_and_expire_after_thirty_days(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push(['data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Winter launch',
                    'spend' => '250.75',
                    'impressions' => '12000',
                    'reach' => '9000',
                    'clicks' => '480',
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => [$this->placementInsightRow(['spend' => '42.50'])]])
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(MetaMarketingCampaignReportSync::class)->sync($connection, $target);

        $this->assertSame('complete', $report->placement_insights['status']);
        $this->assertSame('42.5000', $report->placement_insights['rows'][0]['spend_amount']);
        $this->assertTrue($report->placement_insights_expires_at->isFuture());
        $this->assertStringNotContainsString('facebook', $report->getRawOriginal('placement_insights'));
        $this->assertArrayNotHasKey('placement_insights', $report->toArray());
        Http::assertSentCount(6);
    }

    public function test_placement_report_view_and_csv_are_scoped_to_the_current_users_encrypted_snapshot(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $publisherPlatform = '=1+1';
        $report = SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Winter launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '50.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 50,
            'currency_code' => 'TWD',
            'placement_insights' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-30',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => [$this->placementInsightRow([
                    'publisher_platform' => $publisherPlatform,
                    'platform_position' => 'feed',
                    'spend_amount' => '50.0000',
                    'spend' => '50',
                ])],
            ],
            'placement_insights_expires_at' => now()->addDays(10),
            'synced_at' => now(),
        ]);

        $this->assertStringNotContainsString($publisherPlatform, $report->getRawOriginal('placement_insights'));
        $this->assertArrayNotHasKey('placement_insights', $report->toArray());

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 版位成效')
            ->assertSee('匯出版位 CSV')
            ->assertSee('feed');
        $this->assertSame('complete', $response->viewData('metaMarketingPlacementReport')['status']);

        $csvResponse = $this->get(route('social-engagement-targets.meta-marketing.placements', $target));
        $csvResponse->assertOk();
        $csvLines = str_getcsv($csvResponse->streamedContent(), "\n");
        $csvFields = str_getcsv($csvLines[1]);
        $this->assertSame("'".$publisherPlatform, $csvFields[0]);

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.meta-marketing.placements', $target))
            ->assertNotFound();
    }

    public function test_expired_placement_snapshots_are_cleared_by_the_meta_sync_schedule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget(['ends_on' => '2026-10-01']);
        $report = SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Winter launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '0',
            'impressions' => 0,
            'reach' => 0,
            'clicks' => 0,
            'currency_code' => 'TWD',
            'placement_insights' => [
                'status' => 'complete',
                'rows' => [$this->placementInsightRow()],
            ],
            'placement_insights_expires_at' => now()->subSecond(),
            'synced_at' => now(),
        ]);

        Artisan::call('meta-marketing:sync-due');

        $this->assertNull($report->fresh()->placement_insights);
        $this->assertNull($report->fresh()->placement_insights_expires_at);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 0);
    }

    /** @return array<string, mixed> */
    private function placementInsightRow(array $overrides = []): array
    {
        return array_replace([
            'account_id' => '123456789',
            'campaign_id' => '987654321',
            'publisher_platform' => 'facebook',
            'platform_position' => 'feed',
            'spend' => '50.25',
            'impressions' => '1000',
            'reach' => '800',
            'clicks' => '50',
            'actions' => [['action_type' => 'omni_purchase', 'value' => '1.5']],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '75.5']],
            'account_currency' => 'TWD',
        ], $overrides);
    }

    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'tag' => 'Winter launch',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
            ...$attributes,
        ]);
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
}
