<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\ClientReportSnapshot;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientReportSnapshotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_report_csv_and_snapshot_only_include_the_shared_client_google_ads_account(): void
    {
        $setup = $this->createReportShare();
        $owner = $setup['owner'];
        $sharedClient = $setup['client'];
        $sharedTarget = $setup['target'];
        $periodDate = now()->toDateString();
        $sharedTarget->forceFill([
            'tag' => 'South client campaign',
            'google_ads_campaign_id' => '222222222',
            'starts_on' => $periodDate,
            'ends_on' => $periodDate,
        ])->save();

        $otherClient = Client::factory()->for($owner)->create(['name' => 'North client']);
        $otherTarget = $otherClient->socialEngagementTargets()->create([
            'tag' => 'North client campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 0,
            'starts_on' => $periodDate,
            'ends_on' => $periodDate,
            'google_ads_campaign_id' => '111111111',
        ]);

        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $owner->getKey(),
            'refresh_token' => 'google-ads-test-token',
        ]);

        foreach ([
            [$otherClient, $otherTarget, '1111111111', 'North Ads', '111.0000'],
            [$sharedClient, $sharedTarget, '2222222222', 'South Ads', '222.0000'],
        ] as [$client, $target, $customerId, $customerName, $spendAmount]) {
            $connection->clientAccounts()->create([
                'client_id' => $client->getKey(),
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'currency_code' => 'TWD',
            ]);
            SocialEngagementTargetGoogleAdsReport::query()->create([
                'google_ads_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
                'customer_id' => $customerId,
                'campaign_id' => $target->google_ads_campaign_id,
                'campaign_name' => $target->tag,
                'starts_on' => $periodDate,
                'ends_on' => $periodDate,
                'spend_amount' => $spendAmount,
                'impressions' => 100,
                'clicks' => 10,
                'conversions' => '1.0000',
                'conversions_value' => '300.0000',
                'currency_code' => 'TWD',
                'daily_metrics' => [
                    'status' => 'complete',
                    'requested_start_on' => $periodDate,
                    'requested_end_on' => $periodDate,
                    'coverage_start_on' => $periodDate,
                    'coverage_end_on' => $periodDate,
                    'rows' => [[
                        'date' => $periodDate,
                        'spend_amount' => $spendAmount,
                        'impressions' => 100,
                        'clicks' => 10,
                        'conversions' => '1.0000',
                        'conversions_value' => '300.0000',
                    ]],
                ],
                'synced_at' => now(),
            ]);
        }

        $setup['share']->forceFill([
            'campaign_target_ids' => [$sharedTarget->getKey()],
            'report_sections' => [ClientReportShare::SECTION_GOOGLE_ADS],
            'report_starts_on' => $periodDate,
            'report_ends_on' => $periodDate,
            'compare_previous_period' => false,
        ])->save();

        $this->get(route('client-report-shares.public', ['token' => $setup['token']]))
            ->assertOk()
            ->assertSee('South client campaign')
            ->assertSee('222.00 TWD')
            ->assertDontSee('North client campaign')
            ->assertDontSee('111.00 TWD');

        $liveCsv = $this->get(route('client-report-shares.public.export', ['token' => $setup['token']]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('South client campaign', $liveCsv);
        $this->assertStringContainsString('222.0000', $liveCsv);
        $this->assertStringNotContainsString('North client campaign', $liveCsv);
        $this->assertStringNotContainsString('111.0000', $liveCsv);

        $this->actingAs($owner)
            ->post(route('clients.report-shares.snapshots.store', [$sharedClient, $setup['share']]))
            ->assertRedirect(route('clients.report-shares.index', $sharedClient));

        $snapshot = ClientReportSnapshot::query()->sole();
        $this->assertStringContainsString('South client campaign', $snapshot->csv_content);
        $this->assertStringContainsString('222.0000', $snapshot->csv_content);
        $this->assertStringNotContainsString('North client campaign', $snapshot->csv_content);
        $this->assertStringNotContainsString('111.0000', $snapshot->csv_content);

        $publicSnapshot = $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $snapshot->getKey(),
        ]))->assertOk();
        $this->assertSame($snapshot->csv_content, $publicSnapshot->streamedContent());
    }

    public function test_public_report_csv_and_snapshot_only_include_the_shared_client_ga4_property(): void
    {
        $setup = $this->createReportShare();
        $owner = $setup['owner'];
        $sharedClient = $setup['client'];
        $sharedTarget = $setup['target'];
        $periodDate = now()->toDateString();
        $sharedTarget->forceFill([
            'tag' => 'South client campaign',
            'starts_on' => $periodDate,
            'ends_on' => $periodDate,
        ])->save();

        $otherClient = Client::factory()->for($owner)->create(['name' => 'North client']);
        $otherTarget = $otherClient->socialEngagementTargets()->create([
            'tag' => 'North client campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 0,
            'starts_on' => $periodDate,
            'ends_on' => $periodDate,
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $owner->getKey(),
            'refresh_token' => 'ga4-test-token',
        ]);

        foreach ([
            [$otherClient, $otherTarget, '111111111', 1111, '111.0000'],
            [$sharedClient, $sharedTarget, '222222222', 2222, '222.0000'],
        ] as [$client, $target, $propertyId, $sessions, $revenue]) {
            GoogleAnalyticsClientProperty::query()->create([
                'google_analytics_connection_id' => $connection->getKey(),
                'client_id' => $client->getKey(),
                'property_id' => $propertyId,
            ]);
            SocialEngagementTargetGoogleAnalyticsReport::query()->create([
                'google_analytics_connection_id' => $connection->getKey(),
                'property_id' => $propertyId,
                'social_engagement_target_id' => $target->getKey(),
                'campaign_name' => $target->googleAnalyticsCampaignName(),
                'attribution_mode' => 'manual_utm',
                'source_name' => $target->googleAnalyticsSourceName(),
                'medium_name' => $target->googleAnalyticsMediumName(),
                'starts_on' => $periodDate,
                'ends_on' => $periodDate,
                'sessions' => $sessions,
                'engaged_sessions' => $sessions - 100,
                'key_events' => '22.0000',
                'total_revenue' => $revenue,
                'currency_code' => 'TWD',
                'daily_metrics' => [
                    'status' => 'complete',
                    'requested_start_on' => $periodDate,
                    'requested_end_on' => $periodDate,
                    'coverage_start_on' => $periodDate,
                    'coverage_end_on' => $periodDate,
                    'rows' => [[
                        'date' => $periodDate,
                        'sessions' => $sessions,
                        'engaged_sessions' => $sessions - 100,
                        'key_events' => '22.0000',
                        'total_revenue' => $revenue,
                    ]],
                ],
                'synced_at' => now(),
            ]);
        }

        $setup['share']->forceFill([
            'campaign_target_ids' => [$sharedTarget->getKey()],
            'report_sections' => [ClientReportShare::SECTION_GOOGLE_ANALYTICS],
            'report_starts_on' => $periodDate,
            'report_ends_on' => $periodDate,
            'compare_previous_period' => false,
        ])->save();

        $this->get(route('client-report-shares.public', ['token' => $setup['token']]))
            ->assertOk()
            ->assertSee('South client campaign')
            ->assertSee('2,222')
            ->assertSee('222.00 TWD')
            ->assertDontSee('North client campaign')
            ->assertDontSee('1,111')
            ->assertDontSee('111.00 TWD');

        $liveCsv = $this->get(route('client-report-shares.public.export', ['token' => $setup['token']]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('South client campaign', $liveCsv);
        $this->assertStringContainsString('2222', $liveCsv);
        $this->assertStringContainsString('222.0000', $liveCsv);
        $this->assertStringNotContainsString('North client campaign', $liveCsv);
        $this->assertStringNotContainsString('1111', $liveCsv);
        $this->assertStringNotContainsString('111.0000', $liveCsv);

        $sharedReport = SocialEngagementTargetGoogleAnalyticsReport::query()
            ->where('social_engagement_target_id', $sharedTarget->getKey())
            ->firstOrFail();
        $sharedReport->forceFill(['property_id' => '111111111'])->save();

        $this->get(route('client-report-shares.public', ['token' => $setup['token']]))
            ->assertOk()
            ->assertDontSee('2,222')
            ->assertDontSee('222.00 TWD');
        $mismatchedPropertyCsv = $this->get(route('client-report-shares.public.export', ['token' => $setup['token']]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('2222', $mismatchedPropertyCsv);
        $this->assertStringNotContainsString('222.0000', $mismatchedPropertyCsv);

        $sharedReport->forceFill(['property_id' => '222222222'])->save();
        $this->actingAs($owner)
            ->post(route('clients.report-shares.snapshots.store', [$sharedClient, $setup['share']]))
            ->assertRedirect(route('clients.report-shares.index', $sharedClient));

        $snapshot = ClientReportSnapshot::query()->sole();
        $this->assertStringContainsString('South client campaign', $snapshot->csv_content);
        $this->assertStringContainsString('2222', $snapshot->csv_content);
        $this->assertStringContainsString('222.0000', $snapshot->csv_content);
        $this->assertStringNotContainsString('North client campaign', $snapshot->csv_content);
        $this->assertStringNotContainsString('1111', $snapshot->csv_content);
        $this->assertStringNotContainsString('111.0000', $snapshot->csv_content);

        $publicSnapshot = $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $snapshot->getKey(),
        ]))->assertOk();
        $this->assertSame($snapshot->csv_content, $publicSnapshot->streamedContent());
    }

    public function test_public_report_csv_and_snapshot_only_include_the_shared_client_meta_account(): void
    {
        $setup = $this->createReportShare();
        $owner = $setup['owner'];
        $sharedClient = $setup['client'];
        $sharedTarget = $setup['target'];
        $periodDate = now()->toDateString();
        $sharedTarget->forceFill([
            'tag' => 'South client campaign',
            'meta_campaign_id' => '222222222',
            'starts_on' => $periodDate,
            'ends_on' => $periodDate,
        ])->save();

        $otherClient = Client::factory()->for($owner)->create(['name' => 'North client']);
        $otherTarget = $otherClient->socialEngagementTargets()->create([
            'tag' => 'North client campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 0,
            'starts_on' => $periodDate,
            'ends_on' => $periodDate,
            'meta_campaign_id' => '111111111',
        ]);

        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $owner->getKey(),
            'access_token' => 'meta-test-token',
        ]);

        foreach ([
            [$otherClient, $otherTarget, '111111111', 'North Ads', '111.0000'],
            [$sharedClient, $sharedTarget, '222222222', 'South Ads', '222.0000'],
        ] as [$client, $target, $accountId, $accountName, $spendAmount]) {
            MetaMarketingClientAccount::query()->create([
                'meta_marketing_connection_id' => $connection->getKey(),
                'client_id' => $client->getKey(),
                'ad_account_id' => $accountId,
                'ad_account_name' => $accountName,
                'currency_code' => 'TWD',
            ]);
            SocialEngagementTargetMetaMarketingReport::query()->create([
                'meta_marketing_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
                'ad_account_id' => $accountId,
                'campaign_id' => $target->meta_campaign_id,
                'campaign_name' => $target->tag,
                'starts_on' => $periodDate,
                'ends_on' => $periodDate,
                'spend_amount' => $spendAmount,
                'impressions' => 100,
                'reach' => 90,
                'clicks' => 10,
                'currency_code' => 'TWD',
                'daily_metrics' => [
                    'status' => 'complete',
                    'requested_start_on' => $periodDate,
                    'requested_end_on' => $periodDate,
                    'coverage_start_on' => $periodDate,
                    'coverage_end_on' => $periodDate,
                    'rows' => [[
                        'date' => $periodDate,
                        'spend_amount' => $spendAmount,
                        'impressions' => 100,
                        'reach' => 90,
                        'clicks' => 10,
                    ]],
                ],
                'synced_at' => now(),
            ]);
        }

        $setup['share']->forceFill([
            'campaign_target_ids' => [$sharedTarget->getKey()],
            'report_sections' => [ClientReportShare::SECTION_META_MARKETING],
            'report_starts_on' => $periodDate,
            'report_ends_on' => $periodDate,
            'compare_previous_period' => false,
        ])->save();

        $this->get(route('client-report-shares.public', ['token' => $setup['token']]))
            ->assertOk()
            ->assertSee('South client campaign')
            ->assertSee('222.00 TWD')
            ->assertDontSee('North client campaign')
            ->assertDontSee('111.00 TWD');

        $liveCsv = $this->get(route('client-report-shares.public.export', ['token' => $setup['token']]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('South client campaign', $liveCsv);
        $this->assertStringContainsString('222.0000', $liveCsv);
        $this->assertStringNotContainsString('North client campaign', $liveCsv);
        $this->assertStringNotContainsString('111.0000', $liveCsv);

        $this->actingAs($owner)
            ->post(route('clients.report-shares.snapshots.store', [$sharedClient, $setup['share']]))
            ->assertRedirect(route('clients.report-shares.index', $sharedClient));

        $snapshot = ClientReportSnapshot::query()->sole();
        $this->assertStringContainsString('South client campaign', $snapshot->csv_content);
        $this->assertStringContainsString('222.0000', $snapshot->csv_content);
        $this->assertStringNotContainsString('North client campaign', $snapshot->csv_content);
        $this->assertStringNotContainsString('111.0000', $snapshot->csv_content);

        $publicSnapshot = $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $snapshot->getKey(),
        ]))->assertOk();
        $this->assertSame($snapshot->csv_content, $publicSnapshot->streamedContent());
    }

    public function test_shared_meta_report_hides_rows_saved_from_another_ad_account(): void
    {
        $setup = $this->createReportShare();
        $owner = $setup['owner'];
        $client = $setup['client'];
        $target = $setup['target'];
        $periodDate = now()->toDateString();
        $target->forceFill([
            'tag' => 'South client campaign',
            'meta_campaign_id' => '222222222',
            'starts_on' => $periodDate,
            'ends_on' => $periodDate,
        ])->save();
        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $owner->getKey(),
            'access_token' => 'meta-test-token',
        ]);
        MetaMarketingClientAccount::query()->create([
            'meta_marketing_connection_id' => $connection->getKey(),
            'client_id' => $client->getKey(),
            'ad_account_id' => '222222222',
            'ad_account_name' => 'South Ads',
            'currency_code' => 'TWD',
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'ad_account_id' => '111111111',
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Mismatched account campaign',
            'starts_on' => $periodDate,
            'ends_on' => $periodDate,
            'spend_amount' => '987654.3210',
            'impressions' => 9000,
            'reach' => 8000,
            'clicks' => 700,
            'currency_code' => 'TWD',
            'daily_metrics' => [
                'status' => 'complete',
                'requested_start_on' => $periodDate,
                'requested_end_on' => $periodDate,
                'coverage_start_on' => $periodDate,
                'coverage_end_on' => $periodDate,
                'rows' => [[
                    'date' => $periodDate,
                    'spend_amount' => '987654.3210',
                    'impressions' => 9000,
                    'reach' => 8000,
                    'clicks' => 700,
                ]],
            ],
            'synced_at' => now(),
        ]);
        $setup['share']->forceFill([
            'campaign_target_ids' => [$target->getKey()],
            'report_sections' => [ClientReportShare::SECTION_META_MARKETING],
            'report_starts_on' => $periodDate,
            'report_ends_on' => $periodDate,
            'compare_previous_period' => false,
        ])->save();

        $this->get(route('client-report-shares.public', ['token' => $setup['token']]))
            ->assertOk()
            ->assertSee('South client campaign')
            ->assertDontSee('987,654.32 TWD')
            ->assertDontSee('Mismatched account campaign');

        $liveCsv = $this->get(route('client-report-shares.public.export', ['token' => $setup['token']]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('987654.3210', $liveCsv);
        $this->assertStringNotContainsString('Mismatched account campaign', $liveCsv);

        $this->actingAs($owner)
            ->post(route('clients.report-shares.snapshots.store', [$client, $setup['share']]))
            ->assertRedirect(route('clients.report-shares.index', $client));

        $snapshot = ClientReportSnapshot::query()->sole();
        $this->assertStringNotContainsString('987654.3210', $snapshot->csv_content);
        $this->assertStringNotContainsString('Mismatched account campaign', $snapshot->csv_content);

        $publicSnapshot = $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $snapshot->getKey(),
        ]))->assertOk();
        $this->assertSame($snapshot->csv_content, $publicSnapshot->streamedContent());
        $this->assertStringNotContainsString('987654.3210', $publicSnapshot->streamedContent());
    }

    public function test_owner_can_create_encrypted_snapshot_and_download_it(): void
    {
        $setup = $this->createReportShare();

        $this->actingAs($setup['owner'])
            ->post(route('clients.report-shares.snapshots.store', [$setup['client'], $setup['share']]))
            ->assertRedirect(route('clients.report-shares.index', $setup['client']))
            ->assertSessionHas('status', '已建立報表快照。');

        $snapshot = ClientReportSnapshot::query()->sole();
        $storedCiphertext = DB::table('client_report_snapshots')->where('id', $snapshot->id)->value('csv_content');

        $this->assertSame([ClientReportShare::SECTION_PUBLIC_ENGAGEMENT], $snapshot->report_sections);
        $this->assertSame(now()->toDateString(), $snapshot->report_starts_on->toDateString());
        $this->assertSame(now()->toDateString(), $snapshot->report_ends_on->toDateString());
        $this->assertSame($setup['owner']->id, $snapshot->created_by_user_id);
        $this->assertIsString($storedCiphertext);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $storedCiphertext);
        $this->assertSame(hash('sha256', $snapshot->csv_content), $snapshot->csv_sha256);

        $download = $this->get(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertSame($snapshot->csv_content, $download->streamedContent());

        $managementPage = $this->get(route('clients.report-shares.index', $setup['client']))
            ->assertOk()
            ->assertSee('建立快照')
            ->assertSee('1 份')
            ->assertSee(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]), false);
        $this->assertArrayNotHasKey('csv_content', $managementPage->viewData('shares')->sole()->snapshots->sole()->getAttributes());

        $publicPage = $this->get(route('client-report-shares.public', ['token' => $setup['token']]))
            ->assertOk()
            ->assertSee('封存報表')
            ->assertSee(route('client-report-shares.public.snapshots.export', [
                'token' => $setup['token'],
                'snapshot' => $snapshot->id,
            ]), false);
        $this->assertArrayNotHasKey('csv_content', $publicPage->viewData('share')->snapshots->sole()->getAttributes());
    }

    public function test_snapshot_preserves_the_opted_in_profitability_section(): void
    {
        $setup = $this->createReportShare();
        $setup['share']->forceFill([
            'report_sections' => [ClientReportShare::SECTION_ESTIMATED_PROFITABILITY],
        ])->save();

        $this->actingAs($setup['owner'])
            ->post(route('clients.report-shares.snapshots.store', [$setup['client'], $setup['share']]))
            ->assertRedirect(route('clients.report-shares.index', $setup['client']));

        $snapshot = ClientReportSnapshot::query()->sole();

        $this->assertSame([ClientReportShare::SECTION_ESTIMATED_PROFITABILITY], $snapshot->report_sections);
        $this->assertStringContainsString('估算毛利', $snapshot->csv_content);
        $this->assertStringContainsString('獲利估算狀態', $snapshot->csv_content);
        $this->assertSame(hash('sha256', $snapshot->csv_content), $snapshot->csv_sha256);
    }

    public function test_archived_csv_is_unchanged_when_live_public_engagement_changes(): void
    {
        $setup = $this->createReportShare();

        $this->actingAs($setup['owner'])
            ->post(route('clients.report-shares.snapshots.store', [$setup['client'], $setup['share']]))
            ->assertRedirect();

        $snapshot = ClientReportSnapshot::query()->sole();
        $archivedCsv = $snapshot->csv_content;
        $run = CheckRun::query()->where('monitor_id', $setup['monitor']->id)->sole();
        $run->forceFill([
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 50,
                'comments' => 6,
                'shares' => 4,
            ],
        ])->save();

        $liveCsv = $this->get(route('client-report-shares.public.export', ['token' => $setup['token']]))
            ->assertOk()
            ->streamedContent();
        $publicSnapshot = $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $snapshot->id,
        ]))->assertOk();

        $this->assertSame($archivedCsv, $publicSnapshot->streamedContent());
        $this->assertStringContainsString('13', $archivedCsv);
        $this->assertStringContainsString('60', $liveCsv);
        $this->assertStringNotContainsString('60', $archivedCsv);
        $this->assertSame('noindex, nofollow', $publicSnapshot->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $publicSnapshot->headers->get('Referrer-Policy'));
    }

    public function test_public_snapshot_download_requires_the_active_share_and_own_snapshot(): void
    {
        $setup = $this->createReportShare();
        $snapshot = $setup['share']->snapshots()->create([
            'created_by_user_id' => $setup['owner']->id,
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'report_starts_on' => now()->toDateString(),
            'report_ends_on' => now()->toDateString(),
            'csv_content' => "\xEF\xBB\xBFarchive\n",
            'csv_sha256' => hash('sha256', "\xEF\xBB\xBFarchive\n"),
        ]);

        $otherSetup = $this->createReportShare();
        $otherSnapshot = $otherSetup['share']->snapshots()->create([
            'created_by_user_id' => $otherSetup['owner']->id,
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'csv_content' => "\xEF\xBB\xBFother\n",
            'csv_sha256' => hash('sha256', "\xEF\xBB\xBFother\n"),
        ]);

        $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $otherSnapshot->id,
        ]))->assertNotFound();

        $setup['share']->forceFill(['revoked_at' => now()])->save();

        $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $snapshot->id,
        ]))->assertNotFound();

        $this->actingAs($setup['owner'])
            ->get(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]))
            ->assertOk();

        $expiredSetup = $this->createReportShare();
        $expiredSnapshot = $expiredSetup['share']->snapshots()->create([
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'csv_content' => "\xEF\xBB\xBFexpired\n",
            'csv_sha256' => hash('sha256', "\xEF\xBB\xBFexpired\n"),
        ]);
        $expiredSetup['share']->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $expiredSetup['token'],
            'snapshot' => $expiredSnapshot->id,
        ]))->assertNotFound();
    }

    public function test_internal_snapshot_routes_require_authentication_and_stay_client_scoped(): void
    {
        $setup = $this->createReportShare();
        $snapshot = $setup['share']->snapshots()->create([
            'created_by_user_id' => $setup['owner']->id,
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'csv_content' => "\xEF\xBB\xBFarchive\n",
            'csv_sha256' => hash('sha256', "\xEF\xBB\xBFarchive\n"),
        ]);

        auth()->logout();

        $this->get(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]))
            ->assertRedirect(route('login'));

        $otherOwner = User::factory()->create();

        $this->actingAs($otherOwner)
            ->get(route('clients.report-shares.snapshots.export', [$setup['client']->id, $setup['share']->id, $snapshot->id]))
            ->assertNotFound();
    }

    public function test_snapshot_download_rejects_a_csv_that_fails_its_integrity_hash(): void
    {
        $setup = $this->createReportShare();
        $snapshot = $setup['share']->snapshots()->create([
            'created_by_user_id' => $setup['owner']->id,
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'csv_content' => "\xEF\xBB\xBFarchive\n",
            'csv_sha256' => str_repeat('0', 64),
        ]);

        $this->actingAs($setup['owner'])
            ->get(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]))
            ->assertNotFound();
    }

    /**
     * @return array{owner: User, client: Client, target: SocialEngagementTarget, monitor: Monitor, share: ClientReportShare, token: string}
     */
    private function createReportShare(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $client = Client::factory()->for($owner)->create();
        $target = SocialEngagementTarget::factory()->create([
            'client_id' => $client->id,
            'tag' => 'Snapshot Campaign',
            'social_platform' => 'facebook',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);
        $url = 'https://www.facebook.com/posts/client-report-snapshot';
        $monitor = Monitor::factory()->create([
            'client_id' => $client->id,
            'name' => 'Snapshot post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'normal_status' => 200,
            'scheduled_at' => now()->subMinute(),
            'finished_at' => now(),
            'response_published_at' => now(),
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 10,
                'comments' => 2,
                'shares' => 1,
            ],
        ]);
        $token = bin2hex(random_bytes(32));
        $share = $client->reportShares()->create([
            'name' => 'Snapshot share',
            'token_hash' => hash('sha256', $token),
            'campaign_target_ids' => [$target->id],
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'report_starts_on' => now()->toDateString(),
            'report_ends_on' => now()->toDateString(),
            'compare_previous_period' => false,
            'expires_at' => now()->addDays(30),
        ]);

        return compact('owner', 'client', 'target', 'monitor', 'share', 'token');
    }
}
