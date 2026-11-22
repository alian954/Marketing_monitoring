<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSocialEngagementDataCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_metric_capture_coverage_by_platform_and_retention_period(): void
    {
        $this->travelTo('2026-11-22 20:00:00');
        $facebookCompletePost = $this->createSocialMonitor('facebook', 'COVERAGEFB001', 'campaign');
        $facebookIncompletePost = $this->createSocialMonitor('facebook', 'COVERAGEFB002', 'campaign');
        $facebookWithoutStatusPost = $this->createSocialMonitor('facebook', 'COVERAGEFB003', 'campaign');
        $facebookStaleConfigPost = $this->createSocialMonitor('facebook', 'COVERAGEFB004', 'campaign');
        $facebookOtherTagPost = $this->createSocialMonitor('facebook', 'COVERAGEFB005', 'other');
        $facebookDailySourcePost = $this->createSocialMonitor('facebook', 'COVERAGEFB006', 'campaign');
        $instagramPost = $this->createSocialMonitor('instagram', 'COVERAGEIG001', 'campaign');
        $dcardPost = $this->createSocialMonitor('dcard', '262476944', 'campaign');

        $this->createRun($facebookCompletePost, '2026-11-20 09:00:00', [
            'likes' => 10,
            'comments' => 2,
            'shares' => 1,
        ]);
        $this->createRun($facebookCompletePost, '2026-11-05 09:00:00', [
            'likes' => 5,
            'comments' => 1,
            'shares' => 0,
        ]);
        $this->createRun($facebookIncompletePost, '2026-11-21 10:00:00', [
            'likes' => 20,
            'comments' => 4,
            'shares' => null,
        ]);
        $this->createRun($facebookIncompletePost, '2026-11-22 12:00:00', null, normalStatus: 403);
        $this->createRun($facebookWithoutStatusPost, '2026-11-22 13:00:00', null, normalStatus: null);
        $this->createRun(
            $facebookStaleConfigPost,
            '2026-11-22 13:30:00',
            ['likes' => 30, 'comments' => 5, 'shares' => 2],
            configVersion: $facebookStaleConfigPost->config_version - 1,
        );
        $this->createRun($facebookOtherTagPost, '2026-11-22 14:00:00', [
            'likes' => 40,
            'comments' => 7,
            'shares' => 3,
        ]);
        $this->createRun(
            $facebookDailySourcePost,
            '2026-11-22 14:30:00',
            ['likes' => 50, 'comments' => 9, 'shares' => 5],
            source: 'tls_daily',
        );
        $this->createRun($instagramPost, '2026-11-21 11:00:00', [
            'likes' => 100,
            'comments' => 20,
            'shares' => null,
        ]);
        $this->createRun($dcardPost, '2026-11-21 12:00:00', [
            'likes' => 15,
            'comments' => null,
            'shares' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag' => 'tag:campaign']))
            ->assertOk()
            ->assertSee('互動數擷取覆蓋')
            ->assertSee('包含 4xx／5xx 回應')
            ->assertSee('<th scope="col">擷取覆蓋率</th>', false);

        $coverageRows = collect($response->viewData('socialEngagementDataCoverageRows'))
            ->keyBy(static fn (array $row): string => $row['period_key'].'/'.$row['platform_key']);

        $this->assertSame(['7d/facebook', '7d/instagram', '7d/dcard', '30d/facebook', '30d/instagram', '30d/dcard'], $coverageRows->keys()->all());
        $this->assertSame(3, $coverageRows['7d/facebook']['http_response_count']);
        $this->assertSame(1, $coverageRows['7d/facebook']['complete_metric_read_count']);
        $this->assertSame(33.3, $coverageRows['7d/facebook']['coverage_rate_percent']);
        $this->assertSame(2, $coverageRows['7d/facebook']['posts_with_response_count']);
        $this->assertSame(1, $coverageRows['7d/facebook']['posts_with_metrics_count']);
        $this->assertSame('讚＋留言＋分享', $coverageRows['7d/facebook']['interaction_scope_label']);
        $this->assertSame(1, $coverageRows['7d/instagram']['http_response_count']);
        $this->assertSame(1, $coverageRows['7d/instagram']['complete_metric_read_count']);
        $this->assertSame(100.0, $coverageRows['7d/instagram']['coverage_rate_percent']);
        $this->assertSame('讚＋留言', $coverageRows['7d/instagram']['interaction_scope_label']);
        $this->assertSame(1, $coverageRows['7d/dcard']['http_response_count']);
        $this->assertSame(0, $coverageRows['7d/dcard']['complete_metric_read_count']);
        $this->assertSame(0.0, $coverageRows['7d/dcard']['coverage_rate_percent']);
        $this->assertNotNull($coverageRows['7d/facebook']['latest_complete_capture_at']);
        $this->assertSame(4, $coverageRows['30d/facebook']['http_response_count']);
        $this->assertSame(2, $coverageRows['30d/facebook']['complete_metric_read_count']);
        $this->assertSame(50.0, $coverageRows['30d/facebook']['coverage_rate_percent']);

        $instagramResponse = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'instagram',
                'engagement_tag' => 'tag:campaign',
            ]))
            ->assertOk();

        $this->assertSame(
            ['instagram'],
            array_values(array_unique(array_column($instagramResponse->viewData('socialEngagementDataCoverageRows'), 'platform_key'))),
        );
    }

    public function test_dashboard_explains_when_no_http_response_data_is_available(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('尚無互動數擷取資料')
            ->assertSee('至少保留 7 天歷史資料');

        $this->assertSame([], $response->viewData('socialEngagementDataCoverageRows'));
    }

    public function test_dashboard_data_coverage_respects_history_retention(): void
    {
        $this->travelTo('2026-11-22 20:00:00');
        config(['monitoring.retention_days' => 14]);
        $monitor = $this->createSocialMonitor('facebook', 'COVERAGERETENTION001', 'campaign');
        $this->createRun($monitor, '2026-11-20 09:00:00', [
            'likes' => 10,
            'comments' => 2,
            'shares' => 1,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame(['7d'], array_values(array_unique(array_column(
            $response->viewData('socialEngagementDataCoverageRows'),
            'period_key',
        ))));
    }

    private function createSocialMonitor(string $platform, string $postId, string $tag): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/brand/posts/'.$postId,
            'instagram' => 'https://www.instagram.com/p/'.$postId.'/',
            'threads' => 'https://www.threads.com/@brand/post/'.$postId,
            'dcard' => 'https://www.dcard.tw/f/makeup/p/'.$postId,
        };

        return Monitor::factory()->create([
            'name' => $platform.' '.$postId,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $tag,
        ]);
    }

    /** @param array{views?: int|null, likes?: int|null, comments?: int|null, shares?: int|null}|null $metrics */
    private function createRun(
        Monitor $monitor,
        string $finishedAt,
        ?array $metrics,
        ?int $normalStatus = 200,
        string $source = 'scheduled',
        ?int $configVersion = null,
    ): CheckRun {
        $timestamp = CarbonImmutable::parse($finishedAt, 'Asia/Taipei');

        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $configVersion ?? $monitor->config_version,
            'source' => $source,
            'status' => 'completed',
            'finished_at' => $timestamp,
            'started_at' => $timestamp,
            'scheduled_at' => $timestamp,
            'normal_status' => $normalStatus,
            'error_type' => $normalStatus === null ? 'connect_timeout' : null,
            'response_public_engagement_metrics' => $metrics === null ? null : [
                'views' => $metrics['views'] ?? null,
                'likes' => $metrics['likes'] ?? null,
                'comments' => $metrics['comments'] ?? null,
                'shares' => $metrics['shares'] ?? null,
                'source' => 'json_ld',
            ],
        ]);
    }
}
