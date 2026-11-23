<?php

namespace Tests\Feature;

use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingDataApi;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingAdSetPerformanceReportTest extends TestCase
{
    public function test_ad_set_insights_uses_adset_level_and_normalizes_campaign_metrics(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [
                    $this->adSetInsightRow(10, '50.50'),
                    $this->adSetInsightRow(11, '80.75', [
                        'adset_name' => ' Prospecting ',
                        'impressions' => '1200',
                        'reach' => '900',
                        'clicks' => '60',
                    ]),
                ],
            ]),
        ]);

        $report = (new MetaMarketingDataApi)->campaignAdSetInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );

        $this->assertFalse($report['truncated']);
        $this->assertSame('11', $report['rows'][0]['adset_id']);
        $this->assertSame('Prospecting', $report['rows'][0]['adset_name']);
        $this->assertSame('80.7500', $report['rows'][0]['spend_amount']);
        $this->assertSame(1200, $report['rows'][0]['impressions']);
        $this->assertSame(900, $report['rows'][0]['reach']);
        $this->assertSame(60, $report['rows'][0]['clicks']);
        $this->assertSame([
            ['action_type' => 'omni_purchase', 'value' => '2.5000'],
        ], $report['rows'][0]['actions']);
        $this->assertSame([
            ['action_type' => 'omni_purchase', 'value' => '125.5000'],
        ], $report['rows'][0]['action_values']);
        $this->assertSame('10', $report['rows'][1]['adset_id']);

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/act_123456789/insights')
                && $request->hasHeader('Authorization', 'Bearer access-token')
                && $query['level'] === 'adset'
                && $query['limit'] === '100'
                && $query['fields'] === 'account_id,campaign_id,adset_id,adset_name,spend,impressions,reach,clicks,actions,action_values,account_currency'
                && json_decode($query['filtering'], true)[0]['value'] === '987654321'
                && json_decode($query['time_range'], true) === ['since' => '2026-11-01', 'until' => '2026-11-30'];
        });
    }

    public function test_ad_set_insights_paginates_sorts_and_caps_the_top_200_rows(): void
    {
        Http::preventStrayRequests();
        $firstPage = array_map(fn (int $index): array => $this->adSetInsightRow(10000 + $index, (string) ($index + 1)), range(0, 99));
        $secondPage = array_map(fn (int $index): array => $this->adSetInsightRow(10000 + $index, (string) ($index + 1)), range(100, 199));
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push([
                    'data' => $firstPage,
                    'paging' => [
                        'cursors' => ['after' => 'adsets-next-page'],
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=adsets-next-page',
                    ],
                ])
                ->push([
                    'data' => $secondPage,
                    'paging' => [
                        'cursors' => ['after' => 'adsets-last-page'],
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=adsets-last-page',
                    ],
                ])
                ->push(['data' => [$this->adSetInsightRow(10200, '201')]]),
        ]);

        $report = (new MetaMarketingDataApi)->campaignAdSetInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );

        $this->assertCount(200, $report['rows']);
        $this->assertTrue($report['truncated']);
        $this->assertSame('10200', $report['rows'][0]['adset_id']);
        $this->assertSame('10001', $report['rows'][199]['adset_id']);
        $this->assertSame('201.0000', $report['rows'][0]['spend_amount']);
        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'after=adsets-next-page'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'after=adsets-last-page'));
    }

    public function test_ad_set_insights_rejects_identity_and_currency_mismatches(): void
    {
        Http::preventStrayRequests();
        $invalidRows = [
            $this->adSetInsightRow(10, '20', ['account_id' => '999999999']),
            $this->adSetInsightRow(10, '20', ['campaign_id' => '111111111']),
            $this->adSetInsightRow(10, '20', ['adset_id' => 'invalid']),
            $this->adSetInsightRow(10, '20', ['account_currency' => 'USD']),
        ];

        foreach ($invalidRows as $invalidRow) {
            Http::fake([
                'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response(['data' => [$invalidRow]]),
            ]);

            try {
                (new MetaMarketingDataApi)->campaignAdSetInsights(
                    'access-token',
                    '123456789',
                    '987654321',
                    '2026-11-01',
                    '2026-11-30',
                    'TWD',
                );
                $this->fail('Expected Meta to reject an ad-set row with invalid identity or currency.');
            } catch (MetaMarketingApiException) {
                Http::assertSentCount(1);
            }
        }
    }

    public function test_ad_set_insights_rejects_malformed_rows_and_duplicate_ad_set_ids(): void
    {
        Http::preventStrayRequests();
        $malformedPayloads = [
            ['data' => 'invalid'],
            ['data' => [null]],
            ['data' => [$this->adSetInsightRow(10, '20', ['adset_name' => '   '])]],
            ['data' => [$this->adSetInsightRow(10, '20', ['actions' => 'invalid'])]],
            ['data' => [$this->adSetInsightRow(10, '20'), $this->adSetInsightRow(10, '30')]],
        ];

        foreach ($malformedPayloads as $malformedPayload) {
            Http::fake([
                'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response($malformedPayload),
            ]);

            try {
                (new MetaMarketingDataApi)->campaignAdSetInsights(
                    'access-token',
                    '123456789',
                    '987654321',
                    '2026-11-01',
                    '2026-11-30',
                    'TWD',
                );
                $this->fail('Expected malformed or duplicate Meta ad-set data to be rejected.');
            } catch (MetaMarketingApiException) {
                Http::assertSentCount(1);
            }
        }
    }

    public function test_ad_set_insights_rejects_repeated_pagination_cursors(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push([
                    'data' => [$this->adSetInsightRow(10, '20')],
                    'paging' => [
                        'cursors' => ['after' => 'repeated-cursor'],
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=repeated-cursor',
                    ],
                ])
                ->push([
                    'data' => [$this->adSetInsightRow(11, '10')],
                    'paging' => [
                        'cursors' => ['after' => 'repeated-cursor'],
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=repeated-cursor',
                    ],
                ]),
        ]);

        $this->expectException(MetaMarketingApiException::class);

        (new MetaMarketingDataApi)->campaignAdSetInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    public function test_ad_set_insights_rejects_invalid_dates_ids_and_currency_before_api_calls(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $invalidArguments = [
            ['1234', '987654321', '2026-11-01', '2026-11-30', 'TWD'],
            ['123456789', '1234', '2026-11-01', '2026-11-30', 'TWD'],
            ['123456789', '987654321', '2026-11-31', '2026-11-30', 'TWD'],
            ['123456789', '987654321', '2026-11-01', '2026-10-31', 'TWD'],
            ['123456789', '987654321', '2026-11-01', '2026-11-30', 'twd'],
        ];

        foreach ($invalidArguments as [$adAccountId, $campaignId, $startsOn, $endsOn, $currency]) {
            try {
                (new MetaMarketingDataApi)->campaignAdSetInsights(
                    'access-token',
                    $adAccountId,
                    $campaignId,
                    $startsOn,
                    $endsOn,
                    $currency,
                );
                $this->fail('Expected invalid Meta ad-set report arguments to be rejected.');
            } catch (MetaMarketingApiException) {
                Http::assertNothingSent();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function adSetInsightRow(int $id, string $spend, array $overrides = []): array
    {
        return array_replace([
            'account_id' => '123456789',
            'campaign_id' => '987654321',
            'adset_id' => (string) $id,
            'adset_name' => 'Ad set '.$id,
            'spend' => $spend,
            'impressions' => '1000',
            'reach' => '750',
            'clicks' => '50',
            'actions' => [['action_type' => 'omni_purchase', 'value' => '2.5']],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '125.5']],
            'account_currency' => 'TWD',
        ], $overrides);
    }
}
