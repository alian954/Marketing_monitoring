<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsDataApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsSearchTermReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_fetches_the_last_thirty_complete_days_and_caps_results_at_two_hundred(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $rows = [];

        for ($index = 0; $index < 201; $index++) {
            $rows[] = [
                'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                'campaign' => ['id' => '987654321'],
                'adGroup' => ['id' => (string) (555000000 + $index), 'name' => 'Brand ad group '.$index],
                'searchTermView' => ['searchTerm' => $index === 0 ? '=2+2' : 'query '.$index],
                'metrics' => [
                    'costMicros' => $index === 0 ? '25500000' : '1000000',
                    'impressions' => '100',
                    'clicks' => '5',
                    'conversions' => '0',
                    'conversionsValue' => '0',
                ],
            ];
        }

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([['results' => $rows]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignSearchTermInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-08-01',
            '2026-12-30',
            'TWD',
            'SEARCH',
        );

        $this->assertSame('complete', $report['status']);
        $this->assertSame('2026-10-24', $report['window_start_on']);
        $this->assertSame('2026-11-22', $report['window_end_on']);
        $this->assertSame('TWD', $report['currency_code']);
        $this->assertTrue($report['truncated']);
        $this->assertCount(200, $report['rows']);
        $this->assertSame('=2+2', $report['rows'][0]['search_term']);
        $this->assertSame('25.5000', $report['rows'][0]['spend_amount']);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_contains($query, 'FROM search_term_view')
                && str_contains($query, "segments.date BETWEEN '2026-10-24' AND '2026-11-22'")
                && str_contains($query, 'ORDER BY metrics.cost_micros DESC')
                && str_contains($query, 'LIMIT 201');
        });
    }

    public function test_api_skips_unsupported_campaigns_and_periods_without_complete_days(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $dataApi = new GoogleAdsDataApi;

        $performanceMaxReport = $dataApi->campaignSearchTermInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'PERFORMANCE_MAX',
        );
        $futureStartReport = $dataApi->campaignSearchTermInsights(
            'access-token', '1234567890', '987654321', '2026-11-23', '2026-11-30', 'TWD', 'SEARCH',
        );

        $this->assertSame('unsupported', $performanceMaxReport['status']);
        $this->assertSame([], $performanceMaxReport['rows']);
        $this->assertSame('no_complete_days', $futureStartReport['status']);
        $this->assertSame([], $futureStartReport['rows']);
        Http::assertNothingSent();
    }

    public function test_api_rejects_search_term_rows_for_another_campaign_without_exposing_the_term(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [[
                    'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                    'campaign' => ['id' => '987654322'],
                    'adGroup' => ['id' => '555555555', 'name' => 'Private group'],
                    'searchTermView' => ['searchTerm' => 'private medical condition query'],
                    'metrics' => [
                        'costMicros' => '1000000',
                        'impressions' => '10',
                        'clicks' => '1',
                        'conversions' => '0',
                        'conversionsValue' => '0',
                    ],
                ]],
            ]]),
        ]);

        try {
            (new GoogleAdsDataApi)->campaignSearchTermInsights(
                'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'SEARCH',
            );
            $this->fail('A result for another campaign must be rejected.');
        } catch (GoogleAdsApiException $exception) {
            $this->assertStringNotContainsString('private medical condition query', $exception->getMessage());
        }
    }

    public function test_report_sorts_search_terms_and_exports_formula_safe_csv_for_its_owner(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);
        $report = $this->createReport($connection, $target, [
            'search_terms_data' => $this->searchTermsData(),
            'search_terms_expires_at' => now()->addDays(30),
        ]);

        $storedValue = $report->getRawOriginal('search_terms_data');
        $this->assertIsString($storedValue);
        $this->assertStringNotContainsString('=2+2', $storedValue);
        $this->assertArrayNotHasKey('search_terms_data', $report->toArray());

        $this->get(route('social-engagement-targets.google-ads.search-terms', $target))
            ->assertRedirect(route('login'));

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', [$target, 'search_term_sort' => 'roas']))
            ->assertOk()
            ->assertSee('Google Ads 搜尋字詞')
            ->assertSee('最近 30 個完整日')
            ->assertSee('Google Ads 會隱去低量查詢')
            ->assertSee('搜尋字詞超過 200 筆', false)
            ->assertSee('brand exact')
            ->assertSee('有花費無轉換')
            ->assertSee('6.00x');

        $searchTermReport = $response->viewData('googleAdsSearchTermReport');
        $this->assertSame('brand exact', $searchTermReport['rows'][0]['search_term']);
        $this->assertSame(6.0, $searchTermReport['rows'][0]['roas']);

        $csvResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.google-ads.search-terms', $target))
            ->assertDownload('google-ads-search-terms-'.$target->id.'.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvRows = $this->csvRows($csvResponse->streamedContent());
        $this->assertSame([
            'search_term', 'ad_group', 'impressions', 'clicks', 'ctr_percent', 'spend_amount',
            'cost_per_click', 'conversions', 'cost_per_action', 'conversions_value', 'roas',
            'diagnostic_status', 'currency_code', 'window_start_on', 'window_end_on',
        ], $csvRows[0]);
        $this->assertSame("'=2+2", $csvRows[1][0]);
        $this->assertSame('有花費無轉換', $csvRows[1][11]);
        $this->assertSame('brand exact', $csvRows[2][0]);
    }

    public function test_search_term_report_is_scoped_to_the_current_users_google_ads_connection(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($viewer);
        $otherConnection = $this->createConnection($otherUser);
        $this->createReport($otherConnection, $target, [
            'search_terms_data' => [
                ...$this->searchTermsData(),
                'rows' => [[
                    'search_term' => 'other user private query',
                    'ad_group_id' => '555555555',
                    'ad_group_name' => 'Other group',
                    'impressions' => 10,
                    'clicks' => 1,
                    'spend_amount' => '1.0000',
                    'conversions' => '0.0000',
                    'conversions_value' => '0.0000',
                ]],
            ],
            'search_terms_expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($viewer)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertDontSee('other user private query');

        $this->actingAs($viewer)
            ->get(route('social-engagement-targets.google-ads.search-terms', $target))
            ->assertNotFound();
    }

    public function test_scheduled_google_ads_sync_clears_expired_search_term_snapshots(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $report = $this->createReport($connection, $target, [
            'daily_metrics' => ['status' => 'complete', 'rows' => []],
            'search_terms_data' => $this->searchTermsData(),
            'search_terms_expires_at' => now()->subSecond(),
            'synced_at' => now()->subHours(2),
        ]);

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('略過未設定帳戶 0 個，略過近期讀值 1 個')
            ->assertExitCode(0);

        $this->assertNull($report->fresh()->search_terms_data);
        $this->assertNull($report->fresh()->search_terms_expires_at);
        Http::assertNothingSent();
    }

    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        $target = SocialEngagementTarget::factory()->create(array_merge([
            'tag' => 'Winter campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
        ], $attributes));

        $this->bindGoogleAdsTargetFixture($target);

        return $target;
    }

    private function createConnection(User $user): GoogleAdsConnection
    {
        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);

        $this->trackGoogleAdsFixtureConnection($connection);

        return $connection;
    }

    private function createReport(
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
        array $attributes = [],
    ): SocialEngagementTargetGoogleAdsReport {
        return SocialEngagementTargetGoogleAdsReport::query()->create(array_merge([
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $connection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Current campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'clicks' => 40,
            'conversions' => '2.0000',
            'conversions_value' => '200.0000',
            'currency_code' => $connection->currency_code,
            'daily_metrics' => ['status' => 'complete', 'rows' => []],
            'synced_at' => now(),
        ], $attributes));
    }

    /** @return array{status: string, window_start_on: string, window_end_on: string, currency_code: string, truncated: bool, rows: list<array<string, mixed>>} */
    private function searchTermsData(): array
    {
        return [
            'status' => 'complete',
            'window_start_on' => '2026-10-24',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => true,
            'rows' => [
                [
                    'search_term' => '=2+2',
                    'ad_group_id' => '555555555',
                    'ad_group_name' => 'Brand ad group',
                    'impressions' => 100,
                    'clicks' => 5,
                    'spend_amount' => '25.5000',
                    'conversions' => '0.0000',
                    'conversions_value' => '0.0000',
                ],
                [
                    'search_term' => 'brand exact',
                    'ad_group_id' => '555555556',
                    'ad_group_name' => 'Brand ad group',
                    'impressions' => 50,
                    'clicks' => 4,
                    'spend_amount' => '10.0000',
                    'conversions' => '2.0000',
                    'conversions_value' => '60.0000',
                ],
            ],
        ];
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }
}
