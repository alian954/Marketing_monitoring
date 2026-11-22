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
            ->assertSee('<th scope="col">擷取覆蓋率</th>', false)
            ->assertSee('<th scope="col">未完整擷取原因（次）</th>', false)
            ->assertSee('連線逾時');

        $coverageRows = collect($response->viewData('socialEngagementDataCoverageRows'))
            ->keyBy(static fn (array $row): string => $row['period_key'].'/'.$row['platform_key']);

        $this->assertSame(['7d/facebook', '7d/instagram', '7d/dcard', '30d/facebook', '30d/instagram', '30d/dcard'], $coverageRows->keys()->all());
        $this->assertSame(3, $coverageRows['7d/facebook']['http_response_count']);
        $this->assertSame(4, $coverageRows['7d/facebook']['check_count']);
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
        $this->assertSame(5, $coverageRows['30d/facebook']['check_count']);
        $this->assertSame(2, $coverageRows['30d/facebook']['complete_metric_read_count']);
        $this->assertSame(50.0, $coverageRows['30d/facebook']['coverage_rate_percent']);
        $facebookFailureCounts = collect($coverageRows['7d/facebook']['failure_reason_counts'])
            ->keyBy('key')
            ->map(static fn (array $reason): int => $reason['count']);
        $this->assertSame(1, $facebookFailureCounts['http_status_403']);
        $this->assertSame(1, $facebookFailureCounts['partial_metrics']);
        $this->assertSame(1, $facebookFailureCounts['connection_timeout']);

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

    public function test_dashboard_keeps_checks_without_http_responses_and_leaves_coverage_undefined(): void
    {
        $this->travelTo('2026-11-22 20:00:00');
        $monitor = $this->createSocialMonitor('dcard', '262500001', 'campaign');
        $this->createRun($monitor, '2026-11-22 13:00:00', null, normalStatus: null, errorType: 'tls_handshake');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('TLS 連線失敗')
            ->assertSee('0／1');

        $coverageRows = collect($response->viewData('socialEngagementDataCoverageRows'))
            ->keyBy('period_key');

        $this->assertSame(['7d', '30d'], $coverageRows->keys()->all());
        $this->assertSame(1, $coverageRows['7d']['check_count']);
        $this->assertSame(0, $coverageRows['7d']['http_response_count']);
        $this->assertNull($coverageRows['7d']['coverage_rate_percent']);
        $this->assertSame('tls_failure', $coverageRows['7d']['failure_reason_counts'][0]['key']);
    }

    public function test_dashboard_groups_incomplete_capture_reasons_and_keeps_http_coverage_denominator(): void
    {
        $this->travelTo('2026-11-22 20:00:00');
        $monitor = $this->createSocialMonitor('facebook', 'COVERAGECAUSES001', 'campaign');
        $this->createRun($monitor, '2026-11-22 08:00:00', ['likes' => 10, 'comments' => 2, 'shares' => 1]);
        $this->createRun($monitor, '2026-11-22 09:00:00', null, errorType: 'social_access_required');
        $this->createRun($monitor, '2026-11-22 10:00:00', ['likes' => 12, 'comments' => 3, 'shares' => null], errorType: 'body_too_large');
        $this->createRun($monitor, '2026-11-22 11:00:00', null, normalStatus: 403);
        $this->createRun($monitor, '2026-11-22 12:00:00', ['likes' => 15, 'comments' => null, 'shares' => null]);
        $this->createRun($monitor, '2026-11-22 13:00:00', null);
        $this->createRun($monitor, '2026-11-22 14:00:00', null, normalStatus: null, errorType: 'tls_handshake');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('平台要求登入或驗證')
            ->assertSee('回應超過讀取上限')
            ->assertSee('HTTP 403')
            ->assertSee('只讀到部分互動數')
            ->assertSee('頁面未提供可辨識的互動數')
            ->assertSee('TLS 連線失敗');

        $coverageRows = collect($response->viewData('socialEngagementDataCoverageRows'))
            ->keyBy(static fn (array $row): string => $row['period_key'].'/'.$row['platform_key']);
        $coverageRow = $coverageRows['7d/facebook'];
        $failureCounts = collect($coverageRow['failure_reason_counts'])
            ->keyBy('key')
            ->map(static fn (array $reason): int => $reason['count']);

        $this->assertSame(7, $coverageRow['check_count']);
        $this->assertSame(6, $coverageRow['http_response_count']);
        $this->assertSame(1, $coverageRow['complete_metric_read_count']);
        $this->assertSame(16.7, $coverageRow['coverage_rate_percent']);
        $this->assertSame([
            'http_status_403' => 1,
            'metrics_unrecognized' => 1,
            'partial_metrics' => 1,
            'platform_access' => 1,
            'response_too_large' => 1,
            'tls_failure' => 1,
        ], $failureCounts->sortKeys()->all());
        $this->assertSame('TLS 連線失敗', $coverageRow['latest_failure_reason']);
        $this->assertSame('2026-11-22 14:00', $coverageRow['latest_failure_at']);
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
        ?string $errorType = null,
    ): CheckRun {
        $timestamp = CarbonImmutable::parse($finishedAt, 'Asia/Taipei')->utc();

        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $configVersion ?? $monitor->config_version,
            'source' => $source,
            'status' => 'completed',
            'finished_at' => $timestamp,
            'started_at' => $timestamp,
            'scheduled_at' => $timestamp,
            'normal_status' => $normalStatus,
            'error_type' => $errorType ?? ($normalStatus === null ? 'connect_timeout' : null),
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
