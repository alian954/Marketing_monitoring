<?php

namespace Tests\Feature;

use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Services\MetaMarketingAdPerformanceReport;
use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingCampaignReportSync;
use App\Services\MetaMarketingDataApi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingAdReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_ad_insights_paginates_normalizes_and_limits_to_the_top_200_rows(): void
    {
        Http::preventStrayRequests();
        $firstPage = array_map(fn (int $index): array => $this->adInsightRow($index, (string) ($index * 100 + 1)), range(0, 99));
        $secondPage = array_map(fn (int $index): array => $this->adInsightRow($index, (string) ($index * 100 + 1)), range(100, 199));
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push([
                    'data' => $firstPage,
                    'paging' => [
                        'cursors' => ['after' => 'ads-next-page'],
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=ads-next-page',
                    ],
                ])
                ->push([
                    'data' => $secondPage,
                    'paging' => [
                        'cursors' => ['after' => 'ads-last-page'],
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=ads-last-page',
                    ],
                ])
                ->push(['data' => [$this->adInsightRow(200, '20001')]]),
        ]);

        $report = (new MetaMarketingDataApi)->campaignAdInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );

        $this->assertCount(200, $report['rows']);
        $this->assertTrue($report['truncated']);
        $this->assertSame('20001.0000', $report['rows'][0]['spend_amount']);
        $this->assertSame('10001', $report['rows'][199]['ad_id']);
        $this->assertSame([
            ['action_type' => 'omni_purchase', 'value' => '2.5000'],
        ], $report['rows'][0]['actions']);
        Http::assertSentCount(3);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/act_123456789/insights')
                && $request->hasHeader('Authorization', 'Bearer access-token')
                && $query['level'] === 'ad'
                && $query['limit'] === '100'
                && $query['fields'] === 'account_id,campaign_id,adset_id,adset_name,ad_id,ad_name,spend,impressions,reach,clicks,actions,action_values,account_currency'
                && json_decode($query['filtering'], true)[0]['value'] === '987654321'
                && json_decode($query['time_range'], true) === ['since' => '2026-11-01', 'until' => '2026-11-30'];
        });
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'after=ads-next-page'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'after=ads-last-page'));
    }

    public function test_ad_insights_rejects_rows_outside_the_requested_campaign(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [$this->adInsightRow(0, '10', ['campaign_id' => '111111111'])],
            ]),
        ]);

        $this->expectException(MetaMarketingApiException::class);

        (new MetaMarketingDataApi)->campaignAdInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    public function test_ad_insights_failure_does_not_block_campaign_report_or_paid_snapshot(): void
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
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '2']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '125.50']],
                    'account_currency' => 'TWD',
                ]]])
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
        $this->assertSame('unavailable', $report->ad_insights['status']);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 1);
    }

    public function test_successful_ad_insights_are_saved_encrypted_with_a_thirty_day_expiry(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push(['data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Winter launch',
                    'spend' => '42.50',
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '40',
                    'account_currency' => 'TWD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => [$this->adInsightRow(0, '42.5')]])
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(MetaMarketingCampaignReportSync::class)->sync($connection, $target);

        $this->assertSame('complete', $report->ad_insights['status']);
        $this->assertSame('10000', $report->ad_insights['rows'][0]['ad_id']);
        $this->assertSame('42.5000', $report->ad_insights['rows'][0]['spend_amount']);
        $this->assertTrue($report->ad_insights_expires_at->isFuture());
        $this->assertStringNotContainsString('Winter Ad 10000', $report->getRawOriginal('ad_insights'));
        $this->assertArrayNotHasKey('ad_insights', $report->toArray());
        Http::assertSentCount(5);
    }

    public function test_ad_report_derives_metrics_sorts_rows_and_marks_expired_data(): void
    {
        $builder = new MetaMarketingAdPerformanceReport;
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-30',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [
                $this->adInsightRow(1, '20', [
                    'impressions' => '100',
                    'reach' => '80',
                    'clicks' => '10',
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '2']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '200']],
                ]),
                $this->adInsightRow(2, '50', [
                    'impressions' => '500',
                    'reach' => '400',
                    'clicks' => '20',
                    'actions' => [],
                    'action_values' => [],
                ]),
            ],
        ];

        $report = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'ctr');

        $this->assertSame('complete', $report['status']);
        $this->assertSame('ctr', $report['sort']);
        $this->assertSame('10001', $report['rows'][0]['ad_id']);
        $this->assertSame(10.0, $report['rows'][0]['ctr_percent']);
        $this->assertSame(2.0, (float) $report['rows'][0]['purchases']);
        $this->assertSame(2.0, $report['rows'][0]['cost_per_click']);
        $this->assertSame('expired', $builder->build($snapshot, CarbonImmutable::now()->subSecond())['status']);
        $this->assertSame([], $builder->build($snapshot, CarbonImmutable::now()->subSecond())['rows']);
    }

    public function test_report_view_and_csv_export_use_the_current_users_encrypted_ad_snapshot(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $adName = '=HYPERLINK("https://example.test","bad")';
        $report = SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Winter launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '250.7500',
            'impressions' => 12000,
            'reach' => 9000,
            'clicks' => 480,
            'actions' => [],
            'action_values' => [],
            'currency_code' => 'TWD',
            'ad_insights' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-30',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => [$this->adInsightRow(0, '25.5', [
                    'ad_name' => $adName,
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '40',
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '2']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '75.5']],
                ])],
            ],
            'ad_insights_expires_at' => now()->addDays(10),
            'synced_at' => now(),
        ]);

        $this->assertStringNotContainsString($adName, $report->getRawOriginal('ad_insights'));
        $this->assertArrayNotHasKey('ad_insights', $report->toArray());

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $this->assertSame('complete', $response->viewData('metaMarketingAdReport')['status']);
        $this->assertCount(1, $response->viewData('metaMarketingAdReport')['rows']);
        $response
            ->assertSee('Meta Ads 廣告成效')
            ->assertSee('匯出廣告明細 CSV')
            ->assertSee('10000');

        $csvResponse = $this->get(route('social-engagement-targets.meta-marketing.ads', $target));
        $csvResponse->assertOk();
        $csvLines = str_getcsv($csvResponse->streamedContent(), "\n");
        $csvFields = str_getcsv($csvLines[1]);
        $this->assertSame("'".$adName, $csvFields[1]);

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.meta-marketing.ads', $target))
            ->assertNotFound();
    }

    public function test_expired_ad_snapshots_are_cleared_by_the_meta_sync_schedule(): void
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
            'ad_insights' => ['status' => 'complete', 'rows' => []],
            'ad_insights_expires_at' => now()->subSecond(),
            'synced_at' => now(),
        ]);

        Artisan::call('meta-marketing:sync-due');

        $this->assertNull($report->fresh()->ad_insights);
        $this->assertNull($report->fresh()->ad_insights_expires_at);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 0);
    }

    /** @return array<string, mixed> */
    private function adInsightRow(int $index, string $spend, array $overrides = []): array
    {
        return array_replace([
            'account_id' => '123456789',
            'campaign_id' => '987654321',
            'adset_id' => '456789123',
            'adset_name' => 'Winter ad set',
            'ad_id' => (string) (10000 + $index),
            'ad_name' => 'Winter Ad '.(10000 + $index),
            'spend' => $spend,
            'spend_amount' => $spend,
            'impressions' => '1000',
            'reach' => '800',
            'clicks' => '40',
            'actions' => [['action_type' => 'omni_purchase', 'value' => '2.5']],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '125.5']],
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
