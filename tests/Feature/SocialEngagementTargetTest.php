<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use Database\Seeders\SocialEngagementTargetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_engagement_targets_require_authentication(): void
    {
        $this->post(route('social-engagement-targets.store'), [
            'tag' => 'Campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_dashboard_exposes_a_labeled_ga4_medium_input(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('GA4 媒介（utm_medium）')
            ->assertSee('id="social-engagement-target-ga4-medium"', false)
            ->assertSee('name="ga4_medium_name"', false)
            ->assertSee('aria-labelledby="social-engagement-targets-title"', false);
    }

    public function test_dashboard_exposes_labeled_ga4_business_goal_inputs(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('GA4 商業目標')
            ->assertSee('name="ga4_goal_metric"', false)
            ->assertSee('name="ga4_goal_value"', false)
            ->assertSee('name="ga4_goal_currency"', false)
            ->assertSee('營收目標需填', false);
    }

    public function test_authenticated_user_can_create_and_update_a_ga4_business_goal(): void
    {
        $this->createSocialMonitor('instagram', 'IG-GOAL-01', 'Spring launch');
        $user = User::factory()->create();
        $campaignDates = [
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ];

        $this->actingAs($user)
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Spring launch',
                'social_platform' => 'instagram',
                'target_interactions' => 1000,
                ...$campaignDates,
                'ga4_goal_metric' => 'key_events',
                'ga4_goal_value' => '25.5000',
                'ga4_goal_currency' => 'TWD',
            ])
            ->assertRedirect(route('dashboard', [
                'engagement_platform' => 'instagram',
                'engagement_tag' => 'tag:Spring launch',
            ]));

        $target = SocialEngagementTarget::query()->sole();
        $this->assertSame('key_events', $target->ga4_goal_metric);
        $this->assertSame('25.5000', $target->ga4_goal_value);
        $this->assertNull($target->ga4_goal_currency);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Spring launch',
                'social_platform' => 'instagram',
                'target_interactions' => 1000,
                ...$campaignDates,
                'ga4_goal_metric' => 'total_revenue',
                'ga4_goal_value' => '10000.0000',
                'ga4_goal_currency' => 'TWD',
            ])
            ->assertRedirect(route('dashboard', [
                'engagement_platform' => 'instagram',
                'engagement_tag' => 'tag:Spring launch',
            ]));

        $target->refresh();
        $this->assertDatabaseCount('social_engagement_targets', 1);
        $this->assertSame('total_revenue', $target->ga4_goal_metric);
        $this->assertSame('10000.0000', $target->ga4_goal_value);
        $this->assertSame('TWD', $target->ga4_goal_currency);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Spring launch',
                'social_platform' => 'instagram',
                'target_interactions' => 1000,
                ...$campaignDates,
                'ga4_goal_metric' => '',
                'ga4_goal_value' => '10000.0000',
                'ga4_goal_currency' => 'TWD',
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertNull($target->ga4_goal_metric);
        $this->assertNull($target->ga4_goal_value);
        $this->assertNull($target->ga4_goal_currency);
    }

    public function test_ga4_goal_requires_a_positive_value_and_campaign_period(): void
    {
        $this->createSocialMonitor('instagram', 'IG-GOAL-02', 'Spring launch');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Spring launch',
                'social_platform' => 'instagram',
                'target_interactions' => 1000,
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'ga4_goal_metric' => 'key_events',
                'ga4_goal_value' => '0',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', [
                'ga4_goal_value' => 'GA4 商業目標必須大於 0。',
            ]);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Spring launch',
                'social_platform' => 'instagram',
                'target_interactions' => 1000,
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'ga4_goal_metric' => 'key_events',
                'ga4_goal_value' => '25.12345',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', [
                'ga4_goal_value' => 'GA4 商業目標最多可輸入 4 位小數。',
            ]);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Spring launch',
                'social_platform' => 'instagram',
                'target_interactions' => 1000,
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'ga4_goal_metric' => 'key_events',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', [
                'ga4_goal_value' => '請輸入 GA4 商業目標數值。',
            ]);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Spring launch',
                'social_platform' => 'instagram',
                'target_interactions' => 1000,
                'ga4_goal_metric' => 'key_events',
                'ga4_goal_value' => '10',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', [
                'ga4_goal_metric' => '設定 GA4 商業目標前，請先設定活動期間。',
            ]);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_revenue_goal_requires_a_valid_ga4_currency_code(): void
    {
        $this->createSocialMonitor('instagram', 'IG-GOAL-03', 'Spring launch');
        $user = User::factory()->create();
        $payload = [
            'tag' => 'Spring launch',
            'social_platform' => 'instagram',
            'target_interactions' => 1000,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_goal_metric' => 'total_revenue',
            'ga4_goal_value' => '10000',
        ];

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), $payload)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', [
                'ga4_goal_currency' => '營收目標需要設定幣別。',
            ]);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [...$payload, 'ga4_goal_currency' => 'twd'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', [
                'ga4_goal_currency' => '營收目標幣別請使用 3 位大寫代碼，例如 TWD。',
            ]);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_invalid_ga4_goal_metric_is_rejected(): void
    {
        $this->createSocialMonitor('instagram', 'IG-GOAL-05', 'Spring launch');

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Spring launch',
                'social_platform' => 'instagram',
                'target_interactions' => 1000,
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'ga4_goal_metric' => 'views',
                'ga4_goal_value' => '100',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', [
                'ga4_goal_metric' => 'GA4 商業目標指標無效。',
            ]);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_authenticated_user_can_create_and_update_a_tag_platform_target(): void
    {
        $this->createSocialMonitor('facebook', 'POST123', 'Summer launch');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('dashboard', ['engagement_platform' => 'facebook', 'engagement_tag' => 'tag:Summer launch']))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'facebook',
                'target_interactions' => 2500,
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-30',
                'ga4_campaign_name' => 'summer_launch_2026',
                'ga4_source_name' => 'facebook',
                'ga4_medium_name' => 'paid_social',
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:Summer launch',
            ])
            ->assertRedirect(route('dashboard', ['engagement_platform' => 'facebook', 'engagement_tag' => 'tag:Summer launch']))
            ->assertSessionHas('status');

        $target = SocialEngagementTarget::query()->sole();
        $this->assertSame('Summer launch', $target->tag);
        $this->assertSame('facebook', $target->social_platform);
        $this->assertSame(2500, $target->target_interactions);
        $this->assertSame('2026-11-01', $target->starts_on?->toDateString());
        $this->assertSame('2026-11-30', $target->ends_on?->toDateString());
        $this->assertSame('summer_launch_2026', $target->ga4_campaign_name);
        $this->assertSame('facebook', $target->ga4_source_name);
        $this->assertSame('paid_social', $target->ga4_medium_name);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'facebook',
                'target_interactions' => 3000,
                'ga4_campaign_name' => 'summer_launch_refresh',
                'ga4_source_name' => 'fb',
                'ga4_medium_name' => 'organic_social',
            ])
            ->assertRedirect(route('dashboard', ['engagement_platform' => 'facebook', 'engagement_tag' => 'tag:Summer launch']));

        $this->assertDatabaseCount('social_engagement_targets', 1);
        $this->assertSame(3000, $target->fresh()->target_interactions);
        $this->assertSame('summer_launch_refresh', $target->fresh()->ga4_campaign_name);
        $this->assertSame('fb', $target->fresh()->ga4_source_name);
        $this->assertSame('organic_social', $target->fresh()->ga4_medium_name);
        $this->assertNull($target->fresh()->starts_on);
        $this->assertNull($target->fresh()->ends_on);
    }

    public function test_target_validation_rejects_untracked_tags_and_invalid_target_values(): void
    {
        $this->createSocialMonitor('facebook', 'POST123', 'Summer launch');

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Unknown campaign',
                'social_platform' => 'facebook',
                'target_interactions' => 0,
                'ga4_medium_name' => str_repeat('x', 101),
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['tag', 'target_interactions', 'ga4_medium_name']);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_target_validation_rejects_a_tag_that_is_not_tracked_on_the_selected_platform(): void
    {
        $this->createSocialMonitor('facebook', 'POST123', 'Summer launch');

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'instagram',
                'target_interactions' => 100,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['tag']);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_target_validation_requires_both_campaign_dates_and_a_valid_order(): void
    {
        $this->createSocialMonitor('facebook', 'POST123', 'Summer launch');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'facebook',
                'target_interactions' => 100,
                'starts_on' => '2026-11-01',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['starts_on', 'ends_on']);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'facebook',
                'target_interactions' => 100,
                'starts_on' => '2026-11-30',
                'ends_on' => '2026-11-01',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['ends_on']);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'facebook',
                'target_interactions' => 100,
                'starts_on' => '2026-02-30',
                'ends_on' => '2026-03-01',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['starts_on']);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_dashboard_shows_goal_progress_and_coverage_per_platform(): void
    {
        $facebookComplete = $this->createSocialMonitor('facebook', 'FB-COMPLETE', 'Summer launch');
        $this->createMetricRun($facebookComplete, ['likes' => 10, 'comments' => 5, 'shares' => 5]);
        $facebookIncomplete = $this->createSocialMonitor('facebook', 'FB-INCOMPLETE', 'Summer launch');
        $this->createMetricRun($facebookIncomplete, ['likes' => 90, 'comments' => 40, 'shares' => null]);

        $instagram = $this->createSocialMonitor('instagram', 'IG-COMPLETE', 'Summer launch');
        $this->createMetricRun($instagram, ['likes' => 20, 'comments' => 10, 'shares' => 900]);

        SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'facebook',
            'target_interactions' => 50,
        ]);
        SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'instagram',
            'target_interactions' => 60,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag' => 'tag:Summer launch']))
            ->assertOk()
            ->assertSee('活動互動目標')
            ->assertSee('匯出目標 CSV')
            ->assertSee(route('social-engagement-targets.export'))
            ->assertSee('已讀 1／2 篇')
            ->assertSee('20／50')
            ->assertSee('30／60');

        $targets = collect($response->viewData('socialEngagementTargets'))->keyBy('social_platform');

        $this->assertSame(20, $targets['facebook']['current_interactions']);
        $this->assertSame(40.0, $targets['facebook']['completion_percent']);
        $this->assertSame(1, $targets['facebook']['available_post_count']);
        $this->assertSame(2, $targets['facebook']['tracked_post_count']);
        $this->assertSame(30, $targets['instagram']['current_interactions']);
        $this->assertSame(50.0, $targets['instagram']['completion_percent']);
        $this->assertSame(1, $targets['instagram']['available_post_count']);
        $this->assertSame(1, $targets['instagram']['tracked_post_count']);

        $filteredResponse = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:Summer launch',
            ]))
            ->assertOk();

        $this->assertSame(['facebook'], array_column($filteredResponse->viewData('socialEngagementTargets'), 'social_platform'));
    }

    public function test_target_with_no_complete_public_metrics_has_no_completion_percentage(): void
    {
        $this->createSocialMonitor('dcard', '262476944', 'Summer launch');
        SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'dcard',
            'target_interactions' => 100,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag' => 'tag:Summer launch']))
            ->assertOk();

        $target = $response->viewData('socialEngagementTargets')[0];
        $this->assertNull($target['current_interactions']);
        $this->assertNull($target['completion_percent']);
        $this->assertSame(0, $target['available_post_count']);
        $this->assertSame(1, $target['tracked_post_count']);
    }

    public function test_complete_zero_interactions_count_as_zero_percent_instead_of_missing_data(): void
    {
        $monitor = $this->createSocialMonitor('facebook', 'FB-ZERO', 'Summer launch');
        $this->createMetricRun($monitor, ['likes' => 0, 'comments' => 0, 'shares' => 0]);
        $targetModel = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag' => 'tag:Summer launch']))
            ->assertOk();

        $target = $response->viewData('socialEngagementTargets')[0];
        $this->assertSame(0, $target['current_interactions']);
        $this->assertSame(0.0, $target['completion_percent']);
        $this->assertSame(1, $target['available_post_count']);
        $this->assertSame(1, $target['tracked_post_count']);
        $this->assertModelExists($targetModel);
    }

    public function test_campaign_period_counts_only_posts_published_within_the_date_range(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $onStartDate = $this->createSocialMonitor('facebook', 'FB-START', 'Summer launch');
        $this->createMetricRun($onStartDate, ['likes' => 1, 'comments' => 1, 'shares' => 1], '2026-10-31 16:00:00');
        $withinPeriod = $this->createSocialMonitor('facebook', 'FB-WITHIN', 'Summer launch');
        $this->createMetricRun($withinPeriod, ['likes' => 10, 'comments' => 5, 'shares' => 5], '2026-11-10 12:00:00');
        $onEndDate = $this->createSocialMonitor('facebook', 'FB-END', 'Summer launch');
        $this->createMetricRun($onEndDate, ['likes' => 2, 'comments' => 2, 'shares' => 2], '2026-11-30 15:59:59');
        $outsidePeriod = $this->createSocialMonitor('facebook', 'FB-OUTSIDE', 'Summer launch');
        $this->createMetricRun($outsidePeriod, ['likes' => 90, 'comments' => 40, 'shares' => 50], '2026-10-20 12:00:00');
        $unknownPublicationDate = $this->createSocialMonitor('facebook', 'FB-NO-DATE', 'Summer launch');
        $this->createMetricRun($unknownPublicationDate, ['likes' => 30, 'comments' => 10, 'shares' => 10]);
        SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag' => 'tag:Summer launch']))
            ->assertOk()
            ->assertSee('2026-11-01 至 2026-11-30')
            ->assertSee('已讀 3／3 篇')
            ->assertSee('需新增 8 次')
            ->assertSee('剩 9 天')
            ->assertSee('另有 1 篇缺發布時間');

        $target = $response->viewData('socialEngagementTargets')[0];
        $this->assertSame(29, $target['current_interactions']);
        $this->assertSame(29.0, $target['completion_percent']);
        $this->assertSame(3, $target['available_post_count']);
        $this->assertSame(3, $target['tracked_post_count']);
        $this->assertSame(1, $target['unknown_publication_date_count']);
        $this->assertSame('active', $target['pacing_status']);
        $this->assertSame(9, $target['remaining_days']);
        $this->assertSame(8, $target['daily_required_interactions']);
        $this->assertSame('2026-11-01', $target['starts_on']);
        $this->assertSame('2026-11-30', $target['ends_on']);
        $response->assertSee('name="starts_on" type="date" value="2026-11-01"', false);
        $response->assertSee('name="ends_on" type="date" value="2026-11-30"', false);
        $this->assertSame('2026-11-01', $response->viewData('socialEngagementTargetFormTarget')->starts_on?->toDateString());
        $this->assertSame(100, $response->viewData('socialEngagementTargetFormTarget')->target_interactions);
    }

    public function test_active_and_scheduled_campaign_targets_show_daily_interaction_requirements(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $activeMonitor = $this->createSocialMonitor('facebook', 'FB-ACTIVE-PACING', 'Active launch');
        $this->createMetricRun($activeMonitor, ['likes' => 12, 'comments' => 5, 'shares' => 3], '2026-11-20 12:00:00');
        $scheduledMonitor = $this->createSocialMonitor('threads', 'THREAD-SCHEDULED-PACING', 'Future launch');

        SocialEngagementTarget::factory()->create([
            'tag' => 'Active launch',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
            'starts_on' => '2026-11-20',
            'ends_on' => '2026-11-25',
        ]);
        SocialEngagementTarget::factory()->create([
            'tag' => 'Future launch',
            'social_platform' => 'threads',
            'target_interactions' => 101,
            'starts_on' => '2026-11-25',
            'ends_on' => '2026-11-30',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('每日需新增')
            ->assertSee('需新增 20 次')
            ->assertSee('剩 4 天')
            ->assertSee('每日目標 17 次')
            ->assertSee('共 6 天');

        $targets = collect($response->viewData('socialEngagementTargets'))->keyBy('tag');
        $this->assertSame('active', $targets['Active launch']['pacing_status']);
        $this->assertSame(4, $targets['Active launch']['remaining_days']);
        $this->assertSame(20, $targets['Active launch']['daily_required_interactions']);
        $this->assertSame('scheduled', $targets['Future launch']['pacing_status']);
        $this->assertSame(6, $targets['Future launch']['remaining_days']);
        $this->assertSame(17, $targets['Future launch']['daily_required_interactions']);
    }

    public function test_campaign_target_pacing_skips_unavailable_achieved_and_ended_periods(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $unavailableMonitor = $this->createSocialMonitor('facebook', 'FB-UNAVAILABLE-PACING', 'Unavailable launch');
        $achievedMonitor = $this->createSocialMonitor('facebook', 'FB-ACHIEVED-PACING', 'Achieved launch');
        $this->createMetricRun($achievedMonitor, ['likes' => 5, 'comments' => 3, 'shares' => 2], '2026-11-10 12:00:00');
        $endedMonitor = $this->createSocialMonitor('facebook', 'FB-ENDED-PACING', 'Ended launch');
        $this->createMetricRun($endedMonitor, ['likes' => 4, 'comments' => 3, 'shares' => 2], '2026-11-10 12:00:00');
        $unboundedMonitor = $this->createSocialMonitor('facebook', 'FB-UNBOUNDED-PACING', 'Unbounded launch');
        $this->createMetricRun($unboundedMonitor, ['likes' => 4, 'comments' => 3, 'shares' => 2]);

        foreach ([
            ['tag' => 'Unavailable launch', 'target' => 100, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30'],
            ['tag' => 'Achieved launch', 'target' => 10, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30'],
            ['tag' => 'Ended launch', 'target' => 100, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-20'],
            ['tag' => 'Unbounded launch', 'target' => 100, 'starts_on' => null, 'ends_on' => null],
        ] as $target) {
            SocialEngagementTarget::factory()->create([
                'tag' => $target['tag'],
                'social_platform' => 'facebook',
                'target_interactions' => $target['target'],
                'starts_on' => $target['starts_on'],
                'ends_on' => $target['ends_on'],
            ]);
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('尚無完整讀值')
            ->assertSee('已達成')
            ->assertSee('期間已結束')
            ->assertSee('未設定完整期間');

        $targets = collect($response->viewData('socialEngagementTargets'))->keyBy('tag');
        foreach ([
            'Unavailable launch' => 'unavailable',
            'Achieved launch' => 'achieved',
            'Ended launch' => 'ended',
            'Unbounded launch' => 'no_period',
        ] as $tag => $expectedStatus) {
            $this->assertSame($expectedStatus, $targets[$tag]['pacing_status']);
            $this->assertNull($targets[$tag]['remaining_days']);
            $this->assertNull($targets[$tag]['daily_required_interactions']);
        }
    }

    public function test_campaign_target_csv_exports_period_progress_and_read_coverage(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $withinPeriod = $this->createSocialMonitor('facebook', 'FB-REPORT-WITHIN', 'Launch report');
        $this->createMetricRun($withinPeriod, ['likes' => 10, 'comments' => 5, 'shares' => 5], '2026-11-10 12:00:00');
        $unknownPublicationDate = $this->createSocialMonitor('facebook', 'FB-REPORT-NODATE', 'Launch report');
        $this->createMetricRun($unknownPublicationDate, ['likes' => 90, 'comments' => 40, 'shares' => 50]);
        SocialEngagementTarget::factory()->create([
            'tag' => 'Launch report',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $formulaTag = '=SUM(1,1)';
        $formulaTagMonitor = $this->createSocialMonitor('instagram', 'IG-REPORT-FORMULA', $formulaTag);
        $this->createMetricRun($formulaTagMonitor, ['likes' => 2, 'comments' => 3, 'shares' => null]);
        SocialEngagementTarget::factory()->create([
            'tag' => $formulaTag,
            'social_platform' => 'instagram',
            'target_interactions' => 10,
        ]);

        $this->get(route('social-engagement-targets.export'))
            ->assertRedirect(route('login'));

        $response = $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString(
            'social-engagement-targets-',
            (string) $response->headers->get('content-disposition'),
        );

        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $rowsByTag = collect($rows)->keyBy('campaign_tag');
        $this->assertSame('Facebook', $rowsByTag['Launch report']['social_platform']);
        $this->assertSame('2026-11-01', $rowsByTag['Launch report']['campaign_starts_on']);
        $this->assertSame('2026-11-30', $rowsByTag['Launch report']['campaign_ends_on']);
        $this->assertSame('讚＋留言＋分享', $rowsByTag['Launch report']['interaction_metric_scope']);
        $this->assertSame('100', $rowsByTag['Launch report']['target_interactions']);
        $this->assertSame('20', $rowsByTag['Launch report']['current_interactions']);
        $this->assertSame('20.0', $rowsByTag['Launch report']['completion_percent']);
        $this->assertSame('進行中', $rowsByTag['Launch report']['pacing_status']);
        $this->assertSame('9', $rowsByTag['Launch report']['remaining_days']);
        $this->assertSame('9', $rowsByTag['Launch report']['daily_required_interactions']);
        $this->assertSame('1', $rowsByTag['Launch report']['available_post_count']);
        $this->assertSame('1', $rowsByTag['Launch report']['tracked_post_count']);
        $this->assertSame('1', $rowsByTag['Launch report']['unknown_publication_date_count']);
        $this->assertSame('未設定完整期間', $rowsByTag["'=SUM(1,1)"]['pacing_status']);
        $this->assertSame('', $rowsByTag["'=SUM(1,1)"]['daily_required_interactions']);
        $this->assertSame("'=SUM(1,1)", $rowsByTag["'=SUM(1,1)"]['campaign_tag']);
    }

    public function test_local_target_seeder_uses_an_existing_tagged_social_post_and_is_idempotent(): void
    {
        $this->createSocialMonitor('facebook', 'UNTAGGED-POST', null);
        $this->createSocialMonitor('threads', 'THREAD123', 'Spring campaign');
        app()->detectEnvironment(static fn (): string => 'local');
        $seeder = app(SocialEngagementTargetSeeder::class);

        $seeder->run();
        $seeder->run();

        $target = SocialEngagementTarget::query()->sole();
        $this->assertSame('Spring campaign', $target->tag);
        $this->assertSame('threads', $target->social_platform);
        $this->assertSame(1000, $target->target_interactions);
    }

    public function test_authenticated_user_can_delete_a_social_engagement_target(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('social-engagement-targets.destroy', $target))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->assertModelMissing($target);
    }

    private function createSocialMonitor(string $platform, string $postId, ?string $tag): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/share/p/'.$postId.'/',
            'instagram' => 'https://www.instagram.com/reel/'.$postId.'/',
            'threads' => 'https://www.threads.com/share/'.$postId,
            'dcard' => 'https://www.dcard.tw/f/makeup/p/'.$postId,
            default => throw new \InvalidArgumentException('Unsupported social platform.'),
        };

        return Monitor::factory()->create([
            'name' => ucfirst($platform).' '.$postId,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $tag,
        ]);
    }

    /** @param array{likes: ?int, comments: ?int, shares: ?int} $metrics */
    private function createMetricRun(Monitor $monitor, array $metrics, ?string $publishedAt = null): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'response_public_engagement_metrics' => $metrics + ['views' => null, 'source' => 'json_ld'],
            'response_published_at' => $publishedAt,
        ]);
    }

    /** @return list<array<string, string|null>> */
    private function csvRows(string $content): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $headers = fgetcsv($stream, null, ',', '"', '');
        $rows = [];

        if ($headers !== false) {
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rows[] = array_combine($headers, $values);
            }
        }

        fclose($stream);

        return $rows;
    }
}
