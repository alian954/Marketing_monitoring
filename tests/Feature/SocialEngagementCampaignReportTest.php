<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
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

    public function test_report_keeps_only_the_ten_highest_interaction_posts(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Ranking campaign',
            'social_platform' => 'facebook',
        ]);

        for ($rank = 1; $rank <= 12; $rank++) {
            $postName = sprintf('排行貼文 %02d', $rank);
            $monitor = $this->createSocialMonitor('facebook', sprintf('RANK%03d', $rank), 'Ranking campaign', $postName);
            $this->createMetricRun($monitor, ['likes' => $rank, 'comments' => 0, 'shares' => 0]);
        }

        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('排行貼文 12')
            ->assertSee('排行貼文 03')
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

    /** @param array<string, int|null> $metrics */
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
