<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Services\CampaignMarketingEfficiencyReport;
use App\Services\GoogleAnalyticsCampaignTrendReport;
use App\Services\PaidMediaCampaignTrendReport;
use App\Services\SocialEngagementCampaignReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementCampaignReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_report_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->get(route('social-engagement-targets.report', $target))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_exposes_a_report_link_for_each_campaign_target(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Winter campaign',
            'social_platform' => 'facebook',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('social-engagement-targets.report', $target))
            ->assertSee('查看 Winter campaign 的 Facebook 活動報告');
    }

    public function test_report_summarizes_only_campaign_posts_and_marks_incomplete_reads(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Autumn launch',
            'social_platform' => 'facebook',
            'target_interactions' => 500,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);

        $firstPost = $this->createSocialMonitor('facebook', 'AUTUMN001', 'Autumn launch', '第一篇貼文');
        $this->createMetricRun($firstPost, ['likes' => 100, 'comments' => 20, 'shares' => 5, 'views' => 1000], '2026-11-04T08:00:00Z');

        $secondPost = $this->createSocialMonitor('facebook', 'AUTUMN002', 'Autumn launch', '第二篇貼文');
        $this->createMetricRun($secondPost, ['likes' => 20, 'comments' => 4, 'shares' => 1, 'views' => 500], '2026-11-05T08:00:00Z');

        $incompletePost = $this->createSocialMonitor('facebook', 'AUTUMN003', 'Autumn launch', '資料不完整貼文');
        $this->createMetricRun($incompletePost, ['likes' => 9, 'comments' => 2, 'shares' => null, 'views' => 700], '2026-11-06T08:00:00Z');

        $outsideCampaignPost = $this->createSocialMonitor('facebook', 'AUTUMN004', 'Autumn launch', '活動期間外貼文');
        $this->createMetricRun($outsideCampaignPost, ['likes' => 900, 'comments' => 100, 'shares' => 50, 'views' => 5000], '2026-10-31T08:00:00Z');

        $unknownDatePost = $this->createSocialMonitor('facebook', 'AUTUMN005', 'Autumn launch', '缺少發布時間貼文');
        $this->createMetricRun($unknownDatePost, ['likes' => 800, 'comments' => 100, 'shares' => 20, 'views' => 4000]);

        $otherTagPost = $this->createSocialMonitor('facebook', 'OTHER001', 'Different campaign', '其他活動貼文');
        $this->createMetricRun($otherTagPost, ['likes' => 700, 'comments' => 80, 'shares' => 30, 'views' => 3500], '2026-11-07T08:00:00Z');

        $otherPlatformPost = $this->createSocialMonitor('instagram', 'AUTUMN006', 'Autumn launch', '其他平台貼文');
        $this->createMetricRun($otherPlatformPost, ['likes' => 600, 'comments' => 40, 'views' => 3000], '2026-11-08T08:00:00Z');

        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('活動成效報告')
            ->assertSee(route('social-engagement-targets.report.export', $target))
            ->assertSee('匯出 CSV')
            ->assertSee('列印／另存 PDF')
            ->assertSee('第一篇貼文')
            ->assertSee('資料不完整貼文')
            ->assertDontSee('活動期間外貼文')
            ->assertDontSee('其他活動貼文')
            ->assertDontSee('其他平台貼文')
            ->assertSee('150')
            ->assertSee('30.0%')
            ->assertSee('66.7%')
            ->assertSee('另有 1 篇未取得發布時間');
    }

    public function test_campaign_report_projects_interaction_goal_from_comparable_fresh_post_reads(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Forecast campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 250,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-20',
        ]);

        foreach (range(1, 3) as $index) {
            $monitor = $this->createSocialMonitor('facebook', 'FORECAST00'.$index, 'Forecast campaign', '預測貼文 '.$index);
            $this->createMetricRun($monitor, ['likes' => 10, 'comments' => 1, 'shares' => 1], '2026-11-02T08:00:00Z', null, '2026-11-08T09:00:00Z');
            $this->createMetricRun($monitor, ['likes' => 20, 'comments' => 2, 'shares' => 2], '2026-11-02T08:00:00Z', null, '2026-11-10T09:00:00Z');
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('互動目標預測')
            ->assertSee('依目前速度推估可達標')
            ->assertSee('推估期末互動');

        $forecast = $response->viewData('report')['goal_forecast'];

        $this->assertSame('on_track', $forecast['status']);
        $this->assertSame(3, $forecast['comparable_post_count']);
        $this->assertSame(100.0, $forecast['sample_coverage_percent']);
        $this->assertSame(18.0, $forecast['average_daily_interaction_change']);
        $this->assertSame(255, $forecast['projected_interaction_total']);
        $this->assertSame(102.0, $forecast['projected_completion_percent']);
        $this->assertSame('有限樣本', $forecast['confidence_label']);
    }

    public function test_campaign_goal_forecast_uses_platform_interaction_scope_and_nets_decreases(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Declining campaign',
            'social_platform' => 'instagram',
            'target_interactions' => 500,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-20',
        ]);

        foreach (range(1, 3) as $index) {
            $monitor = $this->createSocialMonitor('instagram', 'DECLINE00'.$index, 'Declining campaign', '下降貼文 '.$index);
            $this->createMetricRun($monitor, ['likes' => 100, 'comments' => 20, 'shares' => 100], '2026-11-02T08:00:00Z', null, '2026-11-08T09:00:00Z');
            $this->createMetricRun($monitor, ['likes' => 90, 'comments' => 20, 'shares' => 0], '2026-11-02T08:00:00Z', null, '2026-11-10T09:00:00Z');
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('依目前速度推估未達標');
        $forecast = $response->viewData('report')['goal_forecast'];

        $this->assertSame('at_risk', $forecast['status']);
        $this->assertSame(-15.0, $forecast['average_daily_interaction_change']);
        $this->assertSame(178, $forecast['projected_interaction_total']);
    }

    public function test_campaign_goal_forecast_reports_a_freshly_achieved_goal_without_a_growth_sample(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Achieved campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 20,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-20',
        ]);
        $monitor = $this->createSocialMonitor('facebook', 'ACHIEVED001', 'Achieved campaign', '已達標貼文');
        $this->createMetricRun($monitor, ['likes' => 23, 'comments' => 2, 'shares' => 5], '2026-11-02T08:00:00Z', null, '2026-11-10T09:00:00Z');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('目前已達成互動目標');
        $forecast = $response->viewData('report')['goal_forecast'];

        $this->assertSame('achieved', $forecast['status']);
        $this->assertNull($forecast['projected_interaction_total']);
    }

    public function test_campaign_goal_forecast_requires_enough_fresh_comparable_posts(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Limited forecast campaign',
            'social_platform' => 'threads',
            'target_interactions' => 100,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-20',
        ]);
        $monitor = $this->createSocialMonitor('threads', 'LIMITED001', 'Limited forecast campaign', '樣本不足貼文');
        $this->createMetricRun($monitor, ['likes' => 10, 'comments' => 2, 'shares' => 1], '2026-11-02T08:00:00Z', null, '2026-11-08T09:00:00Z');
        $this->createMetricRun($monitor, ['likes' => 15, 'comments' => 3, 'shares' => 2], '2026-11-02T08:00:00Z', null, '2026-11-10T09:00:00Z');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('資料不足，暫不推估');
        $forecast = $response->viewData('report')['goal_forecast'];

        $this->assertSame('insufficient_data', $forecast['status']);
        $this->assertSame(1, $forecast['comparable_post_count']);
        $this->assertNull($forecast['projected_interaction_total']);
    }

    public function test_campaign_goal_forecast_requires_recent_complete_reads_across_the_campaign(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Stale forecast campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 50,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-20',
        ]);

        foreach (range(1, 3) as $index) {
            $monitor = $this->createSocialMonitor('facebook', 'STALEFORECAST00'.$index, 'Stale forecast campaign', '舊讀值貼文 '.$index);
            $this->createMetricRun($monitor, ['likes' => 10, 'comments' => 1, 'shares' => 1], '2026-11-02T08:00:00Z', null, '2026-11-07T09:00:00Z');
            $this->createMetricRun($monitor, ['likes' => 20, 'comments' => 2, 'shares' => 2], '2026-11-02T08:00:00Z', null, '2026-11-09T09:00:00Z');
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('資料不足，暫不推估');
        $forecast = $response->viewData('report')['goal_forecast'];

        $this->assertSame('insufficient_data', $forecast['status']);
        $this->assertSame(0.0, $forecast['sample_coverage_percent']);
        $this->assertSame(0.0, $forecast['fresh_read_coverage_percent']);
        $this->assertNull($forecast['projected_interaction_total']);
    }

    public function test_campaign_goal_forecast_distinguishes_unstarted_and_ended_campaigns(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $user = User::factory()->create();
        $unstartedTarget = SocialEngagementTarget::factory()->create([
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-11',
            'ends_on' => '2026-11-20',
        ]);
        $endedTarget = SocialEngagementTarget::factory()->create([
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-09',
        ]);

        $unstartedResponse = $this->actingAs($user)->get(route('social-engagement-targets.report', $unstartedTarget))->assertOk();
        $endedResponse = $this->actingAs($user)->get(route('social-engagement-targets.report', $endedTarget))->assertOk();

        $this->assertSame('not_started', $unstartedResponse->viewData('report')['goal_forecast']['status']);
        $this->assertSame('ended', $endedResponse->viewData('report')['goal_forecast']['status']);
    }

    public function test_report_renders_legacy_ga4_breakdown_without_channel_group(): void
    {
        $user = User::factory()->create();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Legacy campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_source_name' => 'facebook',
            'ga4_medium_name' => 'paid_social',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'google-analytics-refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'Legacy campaign',
            'source_name' => 'facebook',
            'medium_name' => 'paid_social',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 20,
            'engaged_sessions' => 15,
            'key_events' => '2.0000',
            'total_revenue' => '100.0000',
            'source_medium_breakdown' => [[
                'source_name' => 'facebook',
                'medium_name' => 'paid_social',
                'sessions' => 20,
                'engaged_sessions' => 15,
                'key_events' => '2.0000',
                'total_revenue' => '100.0000',
            ]],
            'source_medium_breakdown_truncated' => false,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('facebook')
            ->assertSee('paid_social')
            ->assertSee('GA4 活動渠道、來源與媒介的成效比較')
            ->assertSee('—');
    }

    public function test_platform_roas_uses_only_meta_omni_purchase_and_keeps_currencies_separate(): void
    {
        $metaReport = new SocialEngagementTargetMetaMarketingReport([
            'spend_amount' => '100.0000',
            'currency_code' => 'TWD',
            'action_values' => [
                ['action_type' => 'purchase', 'value' => '400.0000'],
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '5000.0000'],
                ['action_type' => 'omni_purchase', 'value' => '700.0000'],
            ],
        ]);
        $googleAdsReport = new SocialEngagementTargetGoogleAdsReport([
            'spend_amount' => '200.0000',
            'conversions_value' => '600.0000',
            'currency_code' => 'USD',
        ]);

        $analysis = app(CampaignMarketingEfficiencyReport::class)->build(
            new SocialEngagementTarget,
            null,
            null,
            $metaReport,
            $googleAdsReport,
        );

        $this->assertSame('700.0000', $analysis['meta_ads_omni_purchase_value']);
        $this->assertSame(7.0, $analysis['meta_ads_purchase_roas']);
        $this->assertSame(3.0, $analysis['google_ads_conversion_value_roas']);
        $this->assertTrue($analysis['platform_currency_mismatch']);
    }

    public function test_platform_roas_requires_values_positive_spend_and_report_currency(): void
    {
        $scenarios = [
            'missing value' => [
                new SocialEngagementTargetMetaMarketingReport([
                    'spend_amount' => '100.0000',
                    'currency_code' => 'TWD',
                    'action_values' => [['action_type' => 'purchase', 'value' => '900.0000']],
                ]),
                new SocialEngagementTargetGoogleAdsReport([
                    'spend_amount' => '100.0000',
                    'currency_code' => 'USD',
                ]),
            ],
            'missing spend' => [
                new SocialEngagementTargetMetaMarketingReport([
                    'currency_code' => 'TWD',
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '900.0000']],
                ]),
                new SocialEngagementTargetGoogleAdsReport([
                    'conversions_value' => '900.0000',
                    'currency_code' => 'USD',
                ]),
            ],
            'zero spend' => [
                new SocialEngagementTargetMetaMarketingReport([
                    'spend_amount' => '0.0000',
                    'currency_code' => 'TWD',
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '900.0000']],
                ]),
                new SocialEngagementTargetGoogleAdsReport([
                    'spend_amount' => '0.0000',
                    'conversions_value' => '900.0000',
                    'currency_code' => 'USD',
                ]),
            ],
            'negative spend' => [
                new SocialEngagementTargetMetaMarketingReport([
                    'spend_amount' => '-1.0000',
                    'currency_code' => 'TWD',
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '900.0000']],
                ]),
                new SocialEngagementTargetGoogleAdsReport([
                    'spend_amount' => '-1.0000',
                    'conversions_value' => '900.0000',
                    'currency_code' => 'USD',
                ]),
            ],
            'missing currency' => [
                new SocialEngagementTargetMetaMarketingReport([
                    'spend_amount' => '100.0000',
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '900.0000']],
                ]),
                new SocialEngagementTargetGoogleAdsReport([
                    'spend_amount' => '100.0000',
                    'conversions_value' => '900.0000',
                ]),
            ],
        ];

        foreach ($scenarios as $scenario => [$metaReport, $googleAdsReport]) {
            $analysis = app(CampaignMarketingEfficiencyReport::class)->build(
                new SocialEngagementTarget,
                null,
                null,
                $metaReport,
                $googleAdsReport,
            );

            $this->assertNull($analysis['meta_ads_purchase_roas'], $scenario.' Meta ROAS');
            $this->assertNull($analysis['google_ads_conversion_value_roas'], $scenario.' Google Ads ROAS');
        }
    }

    public function test_ga4_channel_export_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->get(route('social-engagement-targets.google-analytics.channels', $target))
            ->assertRedirect(route('login'));
    }

    public function test_ga4_channel_export_flattens_rows_and_sanitizes_formula_values(): void
    {
        $user = User::factory()->create();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Channel export',
            'ga4_campaign_name' => 'Channel export',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'google-analytics-refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'Channel export',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 240,
            'engaged_sessions' => 180,
            'key_events' => '12.0000',
            'total_revenue' => '800.0000',
            'source_medium_breakdown' => [[
                'channel_group' => '=Paid Social',
                'source_name' => 'facebook',
                'medium_name' => 'paid_social',
                'sessions' => 240,
                'engaged_sessions' => 180,
                'key_events' => '12.0000',
                'total_revenue' => '800.0000',
            ], [
                'channel_group' => 'Email',
                'source_name' => 'newsletter',
                'medium_name' => 'email',
                'sessions' => 60,
                'engaged_sessions' => 48,
                'key_events' => '3.0000',
                'total_revenue' => '200.0000',
            ]],
            'source_medium_breakdown_truncated' => false,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.google-analytics.channels', $target))
            ->assertDownload('ga4-channel-breakdown-'.$target->id.'.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(3, $rows);
        $this->assertSame([
            'campaign_name',
            'attribution_mode',
            'campaign_id',
            'campaign_start',
            'campaign_end',
            'channel_group',
            'source',
            'medium',
            'sessions',
            'engaged_sessions',
            'key_events',
            'total_revenue',
            'breakdown_truncated',
            'currency_code',
            'synced_at_asia_taipei',
        ], $rows[0]);
        $firstChannel = array_combine($rows[0], $rows[1]);

        $this->assertSame('Channel export', $firstChannel['campaign_name']);
        $this->assertSame('manual_utm', $firstChannel['attribution_mode']);
        $this->assertSame('', $firstChannel['campaign_id']);
        $this->assertSame('2026-11-01', $firstChannel['campaign_start']);
        $this->assertSame('2026-11-30', $firstChannel['campaign_end']);
        $this->assertSame("'=Paid Social", $firstChannel['channel_group']);
        $this->assertSame('facebook', $firstChannel['source']);
        $this->assertSame('paid_social', $firstChannel['medium']);
        $this->assertSame('240', $firstChannel['sessions']);
        $this->assertSame('180', $firstChannel['engaged_sessions']);
        $this->assertSame('12.0000', $firstChannel['key_events']);
        $this->assertSame('800.0000', $firstChannel['total_revenue']);
        $this->assertSame('false', $firstChannel['breakdown_truncated']);
        $this->assertSame('TWD', $firstChannel['currency_code']);
        $this->assertSame('Email', $rows[2][5]);
    }

    public function test_ga4_channel_export_is_rate_limited(): void
    {
        $target = SocialEngagementTarget::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        $route = route('social-engagement-targets.google-analytics.channels', $target);

        for ($requestNumber = 0; $requestNumber < 10; $requestNumber++) {
            $this->get($route)->assertNotFound();
        }

        $this->get($route)->assertTooManyRequests();
    }

    public function test_ga4_channel_export_does_not_expose_another_users_report(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'ga4_campaign_name' => 'Private campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $otherUser = User::factory()->create();
        $otherConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $otherUser->id,
            'property_id' => '987654321',
            'refresh_token' => 'other-google-analytics-refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $otherConnection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'Private campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 999,
            'engaged_sessions' => 999,
            'key_events' => '99.0000',
            'total_revenue' => '9999.0000',
            'source_medium_breakdown' => [[
                'channel_group' => 'Organic Social',
                'source_name' => 'private-source',
                'medium_name' => 'social',
                'sessions' => 999,
                'engaged_sessions' => 999,
                'key_events' => '99.0000',
                'total_revenue' => '9999.0000',
            ]],
            'source_medium_breakdown_truncated' => false,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.google-analytics.channels', $target))
            ->assertNotFound();
    }

    public function test_campaign_report_export_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->get(route('social-engagement-targets.report.export', $target))
            ->assertRedirect(route('login'));
    }

    public function test_campaign_report_export_leaves_unavailable_sources_and_metrics_blank(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Unconnected campaign',
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report.export', $target))
            ->assertDownload('campaign-report-'.$target->id.'.csv');
        $rows = $this->csvRows($response->streamedContent());
        $fields = array_combine($rows[0], $rows[1]);

        $this->assertSame('', $fields['public_interactions']);
        $this->assertSame('', $fields['observed_campaign_interaction_change']);
        $this->assertSame('0', $fields['comparable_interaction_posts']);
        $this->assertSame('0', $fields['tracked_posts']);
        $this->assertSame('', $fields['ga4_sessions']);
        $this->assertSame('', $fields['ga4_is_stale']);
        $this->assertSame('', $fields['meta_spend_amount']);
        $this->assertSame('', $fields['cost_per_campaign_interaction_change']);
        $this->assertSame('資料不足，暫不推估', $fields['interaction_goal_forecast_status']);
        $this->assertSame('', $fields['interaction_goal_forecast_projected_interactions']);
        $this->assertSame('', $fields['meta_ads_is_stale']);
        $this->assertSame('', $fields['google_ads_spend_amount']);
        $this->assertSame('', $fields['google_ads_is_stale']);
        $this->assertSame('', $fields['total_ad_spend_amount']);
        $this->assertSame('', $fields['roas']);
        $this->assertSame('缺少投放花費', $fields['roas_status']);
    }

    public function test_campaign_report_export_includes_social_analytics_and_paid_media_metrics_safely(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => '=2+2',
            'social_platform' => 'facebook',
            'target_interactions' => 250,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'Spring checkout',
            'ga4_source_name' => 'facebook',
            'meta_campaign_id' => '123456789012345',
            'google_ads_campaign_id' => '9876543210123456',
            'campaign_budget_amount' => '500.0000',
            'campaign_budget_currency' => 'TWD',
        ]);
        $googleAnalyticsConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'google-analytics-refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $googleAnalyticsConnection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'Spring checkout',
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => $target->google_ads_campaign_id,
            'source_name' => 'facebook',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 200,
            'engaged_sessions' => 150,
            'key_events' => '10.0000',
            'total_revenue' => '900.0000',
            'source_medium_breakdown' => [[
                'channel_group' => 'Paid Social',
                'source_name' => 'facebook',
                'medium_name' => 'paid_social',
                'sessions' => 200,
                'engaged_sessions' => 150,
                'key_events' => '10.0000',
                'total_revenue' => '900.0000',
            ]],
            'source_medium_breakdown_truncated' => false,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
        $otherUser = User::factory()->create();
        $otherAnalyticsConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $otherUser->id,
            'property_id' => '987654321',
            'refresh_token' => 'other-google-analytics-refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $otherAnalyticsConnection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'Spring checkout',
            'source_name' => 'facebook',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 55555555,
            'engaged_sessions' => 55555555,
            'key_events' => '55555555.0000',
            'total_revenue' => '55555555.0000',
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);
        $metaConnection = MetaMarketingConnection::factory()->create([
            'user_id' => $user->id,
            'ad_account_id' => 'act_12345',
            'currency_code' => 'TWD',
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $metaConnection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Meta spring campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 2000,
            'reach' => 1500,
            'clicks' => 80,
            'actions' => [['action_type' => 'link_click', 'value' => '80.0000']],
            'action_values' => [
                ['action_type' => 'purchase', 'value' => '400.0000'],
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '1200.0000'],
                ['action_type' => 'omni_purchase', 'value' => '900.0000'],
            ],
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
        $googleAdsConnection = GoogleAdsConnection::factory()->create([
            'user_id' => $user->id,
            'customer_id' => '1234567890',
            'currency_code' => 'TWD',
        ]);
        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $googleAdsConnection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $googleAdsConnection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Google spring campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '200.0000',
            'impressions' => 4000,
            'clicks' => 300,
            'conversions' => '15.0000',
            'conversions_value' => '600.0000',
            'conversion_actions' => [[
                'conversion_action_id' => '1234567',
                'conversion_action_name' => 'Purchase',
                'conversions' => '15.0000',
                'conversion_value' => '600.0000',
            ]],
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
        $monitor = $this->createSocialMonitor('facebook', 'EXPORT001', '=2+2', '活動貼文');
        $this->createMetricRun(
            $monitor,
            ['likes' => 100, 'comments' => 20, 'shares' => 5, 'views' => 1000],
            '2026-11-05T08:00:00Z',
            null,
            '2026-11-05 09:00:00',
        );
        $this->createMetricRun(
            $monitor,
            ['likes' => 100, 'comments' => 20, 'shares' => 5, 'views' => 1000],
            '2026-11-05T08:00:00Z',
            null,
            '2026-11-19 09:00:00',
        );
        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 購買 ROAS')
            ->assertSee('9.00x')
            ->assertSee('Google Ads 轉換價值 ROAS')
            ->assertSee('3.00x');

        $googleAnalyticsTrendReport = $this->mock(GoogleAnalyticsCampaignTrendReport::class);
        $googleAnalyticsTrendReport->shouldNotReceive('recentChanges');
        $paidMediaTrendReport = $this->mock(PaidMediaCampaignTrendReport::class);
        $paidMediaTrendReport->shouldNotReceive('metaMarketingChanges');
        $paidMediaTrendReport->shouldNotReceive('googleAdsChanges');

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report.export', $target))
            ->assertDownload('campaign-report-'.$target->id.'.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $fields = array_combine($rows[0], $rows[1]);

        $this->assertSame("'=2+2", $fields['campaign_tag']);
        $this->assertSame('125', $fields['public_interactions']);
        $this->assertSame('0', $fields['observed_campaign_interaction_change']);
        $this->assertSame('1', $fields['comparable_interaction_posts']);
        $this->assertSame('50.0', $fields['target_completion_percent']);
        $this->assertSame('100.0', $fields['capture_coverage_percent']);
        $this->assertSame('1000', $fields['views_total']);
        $this->assertSame('100', $fields['likes_total']);
        $this->assertSame('20', $fields['comments_total']);
        $this->assertSame('5', $fields['shares_total']);
        $this->assertSame('12.50', $fields['engagement_rate_percent']);
        $this->assertSame('200', $fields['ga4_sessions']);
        $this->assertSame('[{"channel_group":"Paid Social","source_name":"facebook","medium_name":"paid_social","sessions":200,"engaged_sessions":150,"key_events":"10.0000","total_revenue":"900.0000"}]', $fields['ga4_source_medium_breakdown_json']);
        $this->assertSame('false', $fields['ga4_source_medium_breakdown_truncated']);
        $this->assertSame('150', $fields['ga4_engaged_sessions']);
        $this->assertSame('10.0000', $fields['ga4_key_events']);
        $this->assertSame('900.0000', $fields['ga4_total_revenue']);
        $this->assertSame('100.0000', $fields['meta_spend_amount']);
        $this->assertSame('200.0000', $fields['google_ads_spend_amount']);
        $this->assertSame('300.0000', $fields['total_ad_spend_amount']);
        $this->assertSame('500.0000', $fields['campaign_budget_amount']);
        $this->assertSame('TWD', $fields['campaign_budget_currency']);
        $this->assertSame('60.00', $fields['budget_utilization_percent']);
        $this->assertSame('73.33', $fields['budget_elapsed_percent']);
        $this->assertSame('366.6667', $fields['budget_expected_spend_amount']);
        $this->assertSame('-66.6667', $fields['budget_pacing_difference_amount']);
        $this->assertSame('409.0909', $fields['budget_projected_spend_amount']);
        $this->assertSame('-90.9091', $fields['budget_projected_overrun_amount']);
        $this->assertSame('in_progress', $fields['budget_status']);
        $this->assertSame('3.00', $fields['roas']);
        $this->assertSame('可計算', $fields['roas_status']);
        $this->assertSame('1.5000', $fields['cost_per_session']);
        $this->assertSame('30.0000', $fields['cost_per_key_event']);
        $this->assertSame('2.4000', $fields['cost_per_public_interaction']);
        $this->assertSame('', $fields['cost_per_campaign_interaction_change']);
        $this->assertSame('[{"action_type":"link_click","value":"80.0000"}]', $fields['meta_actions_json']);
        $this->assertSame('[{"action_type":"purchase","value":"400.0000"},{"action_type":"offsite_conversion.fb_pixel_purchase","value":"1200.0000"},{"action_type":"omni_purchase","value":"900.0000"}]', $fields['meta_action_values_json']);
        $this->assertSame('900.0000', $fields['meta_ads_omni_purchase_value']);
        $this->assertSame('9.00', $fields['meta_ads_purchase_roas']);
        $this->assertSame('[{"conversion_action_id":"1234567","conversion_action_name":"Purchase","conversions":"15.0000","conversion_value":"600.0000"}]', $fields['google_ads_conversion_actions_json']);
        $this->assertSame('3.00', $fields['google_ads_conversion_value_roas']);
        $this->assertSame('1.3333', $fields['meta_ads_frequency']);
        $this->assertSame('4.00', $fields['meta_ads_ctr_percent']);
        $this->assertSame('1.2500', $fields['meta_ads_cost_per_click']);
        $this->assertSame('50.0000', $fields['meta_ads_cost_per_thousand_impressions']);
        $this->assertSame('7.50', $fields['google_ads_ctr_percent']);
        $this->assertSame('0.6667', $fields['google_ads_cost_per_click']);
        $this->assertSame('50.0000', $fields['google_ads_cost_per_thousand_impressions']);
        $this->assertSame('13.3333', $fields['google_ads_cost_per_conversion']);
        $this->assertSame('5.00', $fields['google_ads_conversion_rate_percent']);
        $this->assertSame('false', $fields['ga4_is_stale']);
        $this->assertStringNotContainsString('55555555', $response->streamedContent());
    }

    public function test_report_does_not_use_metrics_from_a_stale_monitor_configuration(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Current campaign',
            'social_platform' => 'threads',
        ]);
        $monitor = $this->createSocialMonitor('threads', 'STALE001', 'Current campaign', '舊設定貼文');
        $monitor->forceFill(['config_version' => 2])->save();
        $this->createMetricRun($monitor, ['likes' => 100, 'comments' => 20, 'shares' => 10], '2026-11-04T08:00:00Z', 1);

        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('舊設定貼文')
            ->assertSee('尚無完整讀值')
            ->assertSee('0／1 篇')
            ->assertDontSee('130');
    }

    public function test_report_handles_campaigns_without_available_posts_and_missing_targets(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Empty campaign',
            'social_platform' => 'instagram',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('此活動期間尚無符合條件的貼文。')
            ->assertSee('完整讀值覆蓋');

        $this->get(route('social-engagement-targets.report', ['socialEngagementTarget' => 999999]))
            ->assertNotFound();
    }

    public function test_report_uses_the_interaction_metrics_supported_by_each_platform(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['facebook', ['likes' => 5, 'comments' => 3, 'shares' => 2], '讚＋留言＋分享', '10'],
            ['threads', ['likes' => 5, 'comments' => 3, 'shares' => 2], '讚＋留言＋分享', '10'],
            ['instagram', ['likes' => 5, 'comments' => 3], '讚＋留言', '8'],
            ['dcard', ['likes' => 5, 'comments' => 3], '讚＋留言', '8'],
        ] as [$platform, $metrics, $scope, $total]) {
            $tag = ucfirst($platform).' campaign';
            $target = SocialEngagementTarget::factory()->create([
                'tag' => $tag,
                'social_platform' => $platform,
                'target_interactions' => 100,
            ]);
            $postId = $platform === 'dcard' ? '12345678' : strtoupper($platform).'001';
            $monitor = $this->createSocialMonitor($platform, $postId, $tag, $tag.' post');
            $this->createMetricRun($monitor, $metrics);

            $this->actingAs($user)
                ->get(route('social-engagement-targets.report', $target))
                ->assertOk()
                ->assertSee($scope)
                ->assertSee($total);
        }
    }

    public function test_campaign_summary_skips_historical_details_without_changing_aggregate_metrics(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Summary campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $monitor = $this->createSocialMonitor('facebook', 'SUMMARY001', 'Summary campaign', '摘要貼文');
        $this->createMetricRun($monitor, ['likes' => 10, 'comments' => 2, 'shares' => 1, 'views' => 100], '2026-11-02T08:00:00Z', null, '2026-11-02T09:00:00Z');
        $this->createMetricRun($monitor, ['likes' => 15, 'comments' => 3, 'shares' => 2, 'views' => 200], '2026-11-02T08:00:00Z', null, '2026-11-10T09:00:00Z');

        $campaignReport = app(SocialEngagementCampaignReport::class);
        $fullReport = $campaignReport->build($target)['report'];
        $summaryReport = $campaignReport->build($target, false)['report'];

        foreach ([
            'tracked_post_count',
            'complete_read_count',
            'capture_coverage_percent',
            'unknown_publication_date_count',
            'interaction_total',
            'observed_interaction_change',
            'comparable_interaction_post_count',
            'target_completion_percent',
            'average_interactions_per_post',
            'engagement_rate_percent',
            'metrics',
        ] as $metric) {
            $this->assertSame($fullReport[$metric], $summaryReport[$metric]);
        }

        $this->assertEquals($fullReport['goal_forecast'], $summaryReport['goal_forecast']);
        $this->assertSame([], $summaryReport['top_posts']);
        $this->assertSame([], $summaryReport['weekly_interaction_changes']);
        $this->assertCount(1, $fullReport['top_posts']);
        $this->assertNotEmpty($fullReport['weekly_interaction_changes']);
    }

    public function test_campaign_interaction_change_uses_complete_period_reads_and_nets_decreases(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Interaction delta campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-20',
        ]);

        $growingPost = $this->createSocialMonitor('facebook', 'DELTA001', 'Interaction delta campaign', '成長貼文');
        $this->createMetricRun($growingPost, ['likes' => 10, 'comments' => 2, 'shares' => 1], '2026-11-05T08:00:00Z', null, '2026-11-05 09:00:00');
        $this->createMetricRun($growingPost, ['likes' => 15, 'comments' => 4, 'shares' => 2], '2026-11-05T08:00:00Z', null, '2026-11-19 09:00:00');
        $this->createMetricRun($growingPost, ['likes' => 30, 'comments' => 8, 'shares' => 4], '2026-11-05T08:00:00Z', null, '2026-11-21 09:00:00');

        $decliningPost = $this->createSocialMonitor('facebook', 'DELTA002', 'Interaction delta campaign', '下降貼文');
        $this->createMetricRun($decliningPost, ['likes' => 20, 'comments' => 3, 'shares' => 2], '2026-11-06T08:00:00Z', null, '2026-11-06 09:00:00');
        $this->createMetricRun($decliningPost, ['likes' => 18, 'comments' => 2, 'shares' => 1], '2026-11-06T08:00:00Z', null, '2026-11-18 09:00:00');

        $incompletePost = $this->createSocialMonitor('facebook', 'DELTA003', 'Interaction delta campaign', '缺少完整成對讀值');
        $this->createMetricRun($incompletePost, ['likes' => 5, 'comments' => 1, 'shares' => 1], '2026-11-07T08:00:00Z', null, '2026-11-07 09:00:00');
        $this->createMetricRun($incompletePost, ['likes' => 9, 'comments' => null, 'shares' => 2], '2026-11-07T08:00:00Z', null, '2026-11-17 09:00:00');

        $singleReadPost = $this->createSocialMonitor('facebook', 'DELTA004', 'Interaction delta campaign', '只有一次讀值');
        $this->createMetricRun($singleReadPost, ['likes' => 100, 'comments' => 10, 'shares' => 5], '2026-11-08T08:00:00Z', null, '2026-11-16 09:00:00');

        $afterCampaignPost = $this->createSocialMonitor('facebook', 'DELTA005', 'Interaction delta campaign', '活動後貼文');
        $this->createMetricRun($afterCampaignPost, ['likes' => 100, 'comments' => 10, 'shares' => 5], '2026-11-21T08:00:00Z', null, '2026-11-21 09:00:00');
        $this->createMetricRun($afterCampaignPost, ['likes' => 200, 'comments' => 20, 'shares' => 10], '2026-11-21T08:00:00Z', null, '2026-11-22 09:00:00');

        $report = app(SocialEngagementCampaignReport::class)->build($target)['report'];

        $this->assertSame(4, $report['observed_interaction_change']);
        $this->assertSame(2, $report['comparable_interaction_post_count']);
        $this->assertSame(4, $report['tracked_post_count']);
        $this->assertNotContains('活動後貼文', array_column($report['top_posts'], 'name'));
    }

    public function test_campaign_interaction_change_is_unavailable_without_a_comparable_pair(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'No comparison campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-20',
        ]);
        $monitor = $this->createSocialMonitor('facebook', 'NOPAIR001', 'No comparison campaign', '單一快照');
        $this->createMetricRun($monitor, ['likes' => 10, 'comments' => 2, 'shares' => 1], '2026-11-05T08:00:00Z', null, '2026-11-05 09:00:00');

        $report = app(SocialEngagementCampaignReport::class)->build($target)['report'];

        $this->assertNull($report['observed_interaction_change']);
        $this->assertSame(0, $report['comparable_interaction_post_count']);
    }

    public function test_report_keeps_only_the_ten_highest_interaction_posts(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Ranking campaign',
            'social_platform' => 'facebook',
        ]);

        for ($rank = 1; $rank <= 12; $rank++) {
            $postName = sprintf('排行貼文 %02d', $rank);
            $monitor = $this->createSocialMonitor('facebook', sprintf('RANK%03d', $rank), 'Ranking campaign', $postName);
            $metrics = ['likes' => $rank, 'comments' => 0, 'shares' => 0];

            if ($rank === 12) {
                $metrics['metric_sources'] = [
                    'views' => 'json_ld',
                    'likes' => 'embedded_json',
                    'comments' => 'meta',
                    'shares' => 'rendered_dom',
                ];
            }

            $this->createMetricRun($monitor, $metrics);
        }

        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('排行貼文 12')
            ->assertSee('排行貼文 03')
            ->assertSee('來源：JSON-LD')
            ->assertSee('來源：內嵌 JSON')
            ->assertSee('來源：Meta 標籤')
            ->assertSee('來源：瀏覽器呈現')
            ->assertDontSee('排行貼文 02');
    }

    public function test_report_groups_observed_campaign_interaction_changes_by_week(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Weekly campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $campaignPost = $this->createSocialMonitor('facebook', 'WEEKLY001', 'Weekly campaign', '活動貼文');
        $this->createMetricRun(
            $campaignPost,
            ['likes' => 10, 'comments' => 2, 'shares' => 1],
            '2026-11-02T08:00:00Z',
            null,
            '2026-11-02T09:00:00Z',
        );
        $this->createMetricRun(
            $campaignPost,
            ['likes' => 15, 'comments' => 3, 'shares' => 3],
            '2026-11-02T08:00:00Z',
            null,
            '2026-11-04T09:00:00Z',
        );
        $this->createMetricRun(
            $campaignPost,
            ['likes' => 16, 'comments' => 8, 'shares' => 5],
            '2026-11-02T08:00:00Z',
            null,
            '2026-11-10T09:00:00Z',
        );

        $otherCampaignPost = $this->createSocialMonitor('facebook', 'WEEKLY002', 'Other campaign', '其他活動貼文');
        $this->createMetricRun(
            $otherCampaignPost,
            ['likes' => 1, 'comments' => 1, 'shares' => 1],
            '2026-11-02T08:00:00Z',
            null,
            '2026-11-02T09:00:00Z',
        );
        $this->createMetricRun(
            $otherCampaignPost,
            ['likes' => 1000, 'comments' => 1000, 'shares' => 1000],
            '2026-11-02T08:00:00Z',
            null,
            '2026-11-10T09:00:00Z',
        );

        $outsidePeriodPost = $this->createSocialMonitor('facebook', 'WEEKLY003', 'Weekly campaign', '期間外貼文');
        $this->createMetricRun(
            $outsidePeriodPost,
            ['likes' => 1, 'comments' => 1, 'shares' => 1],
            '2026-10-31T08:00:00Z',
            null,
            '2026-11-02T09:00:00Z',
        );
        $this->createMetricRun(
            $outsidePeriodPost,
            ['likes' => 1000, 'comments' => 1000, 'shares' => 1000],
            '2026-10-31T08:00:00Z',
            null,
            '2026-11-10T09:00:00Z',
        );

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('每週觀測互動變化')
            ->assertSee('<th scope="col">活動週</th>', false)
            ->assertSee('11/2–11/8')
            ->assertSee('11/9–11/15')
            ->assertSee('首次讀值只作基準');

        $weeklyChanges = $response->viewData('report')['weekly_interaction_changes'];

        $this->assertSame(2, count($weeklyChanges));
        $this->assertSame(8, $weeklyChanges[0]['interaction_change']);
        $this->assertSame(1, $weeklyChanges[0]['compared_post_count']);
        $this->assertSame(8, $weeklyChanges[1]['interaction_change']);
        $this->assertSame(1, $weeklyChanges[1]['compared_post_count']);
    }

    public function test_report_does_not_treat_the_first_complete_read_as_zero_growth(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Baseline campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $monitor = $this->createSocialMonitor('facebook', 'BASELINE001', 'Baseline campaign', '基準貼文');
        $this->createMetricRun(
            $monitor,
            ['likes' => 100, 'comments' => 20, 'shares' => null],
            '2026-11-02T08:00:00Z',
            null,
            '2026-11-02T09:00:00Z',
        );
        $this->createMetricRun(
            $monitor,
            ['likes' => 10, 'comments' => 2, 'shares' => 1],
            '2026-11-02T08:00:00Z',
            null,
            '2026-11-04T09:00:00Z',
        );

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('保留期間內尚無可比較的前後讀值。');

        $this->assertSame([], $response->viewData('report')['weekly_interaction_changes']);
    }

    public function test_campaign_weekly_growth_ignores_reads_from_a_stale_configuration(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Current campaign',
            'social_platform' => 'threads',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $monitor = $this->createSocialMonitor('threads', 'STALE002', 'Current campaign', '舊設定週趨勢貼文');
        $monitor->forceFill(['config_version' => 2])->save();
        $this->createMetricRun($monitor, ['likes' => 100, 'comments' => 20, 'shares' => 10], '2026-11-04T08:00:00Z', 1, '2026-11-05T09:00:00Z');
        $this->createMetricRun($monitor, ['likes' => 150, 'comments' => 25, 'shares' => 20], '2026-11-04T08:00:00Z', 1, '2026-11-06T09:00:00Z');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('保留期間內尚無可比較的前後讀值。')
            ->assertDontSee('舊設定週趨勢貼文');

        $this->assertSame([], $response->viewData('report')['weekly_interaction_changes']);
    }

    private function createSocialMonitor(string $platform, string $postId, string $tag, string $name): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/share/p/'.$postId.'/',
            'instagram' => 'https://www.instagram.com/reel/'.$postId.'/',
            'threads' => 'https://www.threads.com/share/'.$postId,
            'dcard' => 'https://www.dcard.tw/f/makeup/p/'.$postId,
            default => throw new \InvalidArgumentException('Unsupported social platform.'),
        };

        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $tag,
        ]);
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

    /** @param array<string, mixed> $metrics */
    private function createMetricRun(
        Monitor $monitor,
        array $metrics,
        ?string $publishedAt = null,
        ?int $configVersion = null,
        ?string $finishedAt = null,
    ): CheckRun {
        $attributes = [
            'config_version' => $configVersion ?? $monitor->config_version,
            'response_public_engagement_metrics' => $metrics + ['views' => null, 'source' => 'json_ld'],
            'response_published_at' => $publishedAt,
        ];

        if ($finishedAt !== null) {
            $attributes['finished_at'] = $finishedAt;
        }

        return CheckRun::factory()->for($monitor)->create($attributes);
    }
}
