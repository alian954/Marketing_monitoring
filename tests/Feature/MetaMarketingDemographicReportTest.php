<?php

namespace Tests\Feature;

use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingCampaignReportSync;
use App\Services\MetaMarketingDataApi;
use App\Services\MetaMarketingDemographicPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingDemographicReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_demographic_insights_requests_age_and_gender_breakdowns(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [$this->demographicInsightRow([
                    'age' => '25-34',
                    'gender' => 'female',
                    'spend' => '50.25',
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '50',
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '3.5']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '200.75']],
                ])],
            ]),
        ]);

        $result = app(MetaMarketingDataApi::class)->campaignDemographicInsights(
            'access-token', '123456789', '987654321', '2026-11-01', '2026-11-30', 'TWD',
        );

        $this->assertSame('25-34', $result['rows'][0]['age']);
        $this->assertSame('female', $result['rows'][0]['gender']);
        $this->assertSame('50.2500', $result['rows'][0]['spend_amount']);
        $this->assertSame(800, $result['rows'][0]['reach']);
        $this->assertSame([['action_type' => 'omni_purchase', 'value' => '3.5000']], $result['rows'][0]['actions']);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->hasHeader('Authorization', 'Bearer access-token')
                && $query['level'] === 'campaign'
                && $query['breakdowns'] === 'age,gender'
                && $query['limit'] === '100'
                && $query['fields'] === 'account_id,campaign_id,age,gender,spend,impressions,reach,clicks,actions,action_values,account_currency'
                && json_decode($query['filtering'], true)[0]['value'] === '987654321'
                && json_decode($query['time_range'], true) === ['since' => '2026-11-01', 'until' => '2026-11-30'];
        });
        Http::assertSentCount(1);
    }

    public function test_campaign_demographic_insights_rejects_duplicate_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [
                    $this->demographicInsightRow(['age' => '25-34', 'gender' => 'female']),
                    $this->demographicInsightRow(['age' => '25-34', 'gender' => 'FEMALE']),
                ],
            ]),
        ]);

        $this->expectException(MetaMarketingApiException::class);

        app(MetaMarketingDataApi::class)->campaignDemographicInsights(
            'access-token', '123456789', '987654321', '2026-11-01', '2026-11-30', 'TWD',
        );
    }

    public function test_campaign_demographic_insights_follows_pages(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push([
                    'data' => [$this->demographicInsightRow(['age' => '18-24', 'gender' => 'female'])],
                    'paging' => [
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=cursor-a',
                        'cursors' => ['after' => 'cursor-a'],
                    ],
                ])
                ->push(['data' => [$this->demographicInsightRow(['age' => '25-34', 'gender' => 'male'])]]),
        ]);

        $result = app(MetaMarketingDataApi::class)->campaignDemographicInsights(
            'access-token', '123456789', '987654321', '2026-11-01', '2026-11-30', 'TWD',
        );

        $this->assertSame(2, count($result['rows']));
        $this->assertFalse($result['truncated']);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['after'] ?? null) === 'cursor-a';
        });

    }

    public function test_campaign_demographic_insights_limits_rows_to_the_top_200(): void
    {
        Http::preventStrayRequests();
        $rows = [];
        for ($index = 0; $index < 201; $index++) {
            $rows[] = $this->demographicInsightRow([
                'age' => sprintf('%03d+', $index),
                'gender' => 'female',
                'spend' => (string) (300 - $index),
            ]);
        }
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response(['data' => $rows]),
        ]);

        $limitedResult = app(MetaMarketingDataApi::class)->campaignDemographicInsights(
            'access-token', '123456789', '987654321', '2026-11-01', '2026-11-30', 'TWD',
        );

        $this->assertSame(200, count($limitedResult['rows']));
        $this->assertTrue($limitedResult['truncated']);
    }

    public function test_demographic_report_calculates_metrics_sorts_rows_and_hides_expired_data(): void
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-30',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [
                $this->demographicSnapshotRow([
                    'age' => '25-34',
                    'gender' => 'female',
                    'spend_amount' => '60.0000',
                    'impressions' => 2000,
                    'clicks' => 20,
                    'reach' => null,
                ]),
                $this->demographicSnapshotRow([
                    'age' => '35-44',
                    'gender' => 'male',
                    'spend_amount' => '25.0000',
                    'impressions' => 1000,
                    'clicks' => 50,
                    'reach' => 800,
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '2']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '250']],
                ]),
            ],
        ];

        $report = app(MetaMarketingDemographicPerformanceReport::class)->build($snapshot, CarbonImmutable::now()->addDay(), 'clicks');

        $this->assertSame('complete', $report['status']);
        $this->assertSame('male', $report['rows'][0]['gender']);
        $this->assertSame('男性', $report['rows'][0]['gender_label']);
        $this->assertSame(5.0, $report['rows'][0]['ctr_percent']);
        $this->assertSame(0.5, $report['rows'][0]['cost_per_click']);
        $this->assertSame(25.0, $report['rows'][0]['cost_per_thousand_impressions']);
        $this->assertSame('2', $report['rows'][0]['purchases']);
        $this->assertSame('250', $report['rows'][0]['purchase_value']);
        $this->assertNull($report['rows'][1]['reach']);
        $this->assertSame('expired', app(MetaMarketingDemographicPerformanceReport::class)->build(
            ['status' => 'complete', 'rows' => []],
            CarbonImmutable::now()->subSecond(),
        )['status']);
    }

    public function test_demographic_sync_saves_encrypted_data_with_thirty_day_expiry(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push(['data' => [$this->campaignInsightRow()]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => [$this->demographicInsightRow(['age' => '25-34', 'gender' => 'female'])]]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(MetaMarketingCampaignReportSync::class)->sync($connection, $target);

        $this->assertSame('complete', $report->demographic_insights['status']);
        $this->assertSame('25-34', $report->demographic_insights['rows'][0]['age']);
        $this->assertTrue($report->demographic_insights_expires_at->isFuture());
        $this->assertStringNotContainsString('female', $report->getRawOriginal('demographic_insights'));
        $this->assertArrayNotHasKey('demographic_insights', $report->toArray());
        Http::assertSentCount(5);
    }

    public function test_demographic_api_failure_does_not_block_campaign_or_other_meta_reports(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push(['data' => [$this->campaignInsightRow()]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['error' => ['code' => 200, 'message' => 'Permission denied']], 400),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $unavailableReport = app(MetaMarketingCampaignReportSync::class)->sync($connection, $target);

        $this->assertSame('250.7500', $unavailableReport->spend_amount);
        $this->assertSame('complete', $unavailableReport->ad_insights['status']);
        $this->assertSame('complete', $unavailableReport->placement_insights['status']);
        $this->assertSame('unavailable', $unavailableReport->demographic_insights['status']);
        Http::assertSentCount(5);
    }

    public function test_demographic_connection_failure_does_not_block_campaign_or_other_meta_reports(): void
    {
        Http::preventStrayRequests();
        $demographicRequestCount = 0;
        Http::fake(function (ClientRequest $request) use (&$demographicRequestCount): mixed {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['breakdowns'] ?? null) === 'age,gender') {
                $demographicRequestCount++;
                throw new ConnectionException('Meta connection unavailable.');
            }

            return Http::response(['data' => []]);
        });
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(MetaMarketingCampaignReportSync::class)->sync($connection, $target);

        $this->assertSame('complete', $report->ad_insights['status']);
        $this->assertSame('complete', $report->placement_insights['status']);
        $this->assertSame('unavailable', $report->demographic_insights['status']);
        $this->assertSame(1, $demographicRequestCount);
        Http::assertSentCount(4);
    }

    public function test_demographic_report_view_and_csv_are_scoped_to_the_current_users_snapshot(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
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
            'demographic_insights' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-30',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => [$this->demographicSnapshotRow([
                    'age' => '25-34',
                    'gender' => '=1+1',
                    'spend_amount' => '50.0000',
                ])],
            ],
            'demographic_insights_expires_at' => now()->addDays(10),
            'synced_at' => now(),
        ]);

        $this->assertStringNotContainsString('25-34', $report->getRawOriginal('demographic_insights'));
        $this->assertArrayNotHasKey('demographic_insights', $report->toArray());

        $this->get(route('social-engagement-targets.meta-marketing.demographics', $target))
            ->assertRedirect(route('login'));

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 年齡與性別成效')
            ->assertSee('匯出受眾 CSV')
            ->assertSee('25-34')
            ->assertSee('=1+1');
        $this->assertSame('complete', $response->viewData('metaMarketingDemographicReport')['status']);

        $csvResponse = $this->get(route('social-engagement-targets.meta-marketing.demographics', $target));
        $csvResponse->assertOk();
        $csvLines = str_getcsv($csvResponse->streamedContent(), "\n");
        $csvFields = str_getcsv($csvLines[1]);
        $this->assertSame("'=1+1", $csvFields[1]);

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.meta-marketing.demographics', $target))
            ->assertNotFound();

        $report->update(['demographic_insights_expires_at' => now()->subSecond()]);
        $this->actingAs($user)
            ->get(route('social-engagement-targets.meta-marketing.demographics', $target))
            ->assertNotFound();
    }

    public function test_expired_demographic_snapshots_are_cleared_by_the_meta_sync_schedule(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
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
            'demographic_insights' => [
                'status' => 'complete',
                'rows' => [$this->demographicSnapshotRow()],
            ],
            'demographic_insights_expires_at' => now()->subSecond(),
            'synced_at' => now(),
        ]);

        Artisan::call('meta-marketing:sync-due');

        $this->assertNull($report->fresh()->demographic_insights);
        $this->assertNull($report->fresh()->demographic_insights_expires_at);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 0);
    }

    /** @return array<string, mixed> */
    private function demographicInsightRow(array $overrides = []): array
    {
        return array_replace([
            'account_id' => '123456789',
            'campaign_id' => '987654321',
            'age' => '25-34',
            'gender' => 'female',
            'spend' => '50.25',
            'impressions' => '1000',
            'reach' => '800',
            'clicks' => '50',
            'actions' => [['action_type' => 'omni_purchase', 'value' => '1.5']],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '75.5']],
            'account_currency' => 'TWD',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function demographicSnapshotRow(array $overrides = []): array
    {
        return array_replace([
            'age' => '25-34',
            'gender' => 'female',
            'spend_amount' => '50.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 50,
            'actions' => [['action_type' => 'omni_purchase', 'value' => '1.5']],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '75.5']],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function campaignInsightRow(): array
    {
        return [
            'account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Winter launch',
            'spend' => '250.75',
            'impressions' => '12000',
            'reach' => '9000',
            'clicks' => '480',
            'actions' => [],
            'action_values' => [],
            'account_currency' => 'TWD',
        ];
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
