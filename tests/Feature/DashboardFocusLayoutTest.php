<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Client;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFocusLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_keeps_marketing_totals_out_of_the_primary_focus_panel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Monitor::factory()->create([
            'name' => 'summary-monitor',
            'url' => 'https://summary.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://summary.example.test/'),
        ]);
        SocialEngagementTarget::factory()->create([
            'tag' => 'summary-campaign',
            'social_platform' => 'facebook',
        ]);

        $response = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 項')
            ->assertSee('1 個活動');

        $responseContent = $response->getContent();
        $overviewStart = strpos($responseContent, 'id="dashboard-focus-title"');
        $detailsStart = strpos($responseContent, 'id="dashboard-monitor-summary"');

        $this->assertIsInt($overviewStart);
        $this->assertIsInt($detailsStart);

        $overviewMarkup = substr($responseContent, $overviewStart, $detailsStart - $overviewStart);
        $this->assertStringNotContainsString('個活動正在追蹤', $overviewMarkup);
        $this->assertStringNotContainsString('追蹤活動', $overviewMarkup);
        $this->assertStringContainsString('行銷成效', $responseContent);
    }

    public function test_dashboard_shows_key_metrics_and_collapses_detailed_reports_when_no_items_need_attention(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<h1 class="page-title">監測總覽</h1>', false)
            ->assertDontSee('aria-label="待處理 0 項，監測項目 0 項"', false)
            ->assertDontSee('0 項需要處理')
            ->assertSee('尚未新增監測項目')
            ->assertDontSee('dashboard-focus-grid')
            ->assertDontSee('dashboard-summary-grid')
            ->assertSee(route('incidents.index'), false)
            ->assertSee(route('monitors.index'), false)
            ->assertSee(route('features.index'), false)
            ->assertSee('待處理')
            ->assertDontSee('追蹤活動')
            ->assertSee('行銷成效')
            ->assertSee(route('clients.performance'), false)
            ->assertSee('進行中的事件')
            ->assertSee('監測項目')
            ->assertSee('0 個活動')
            ->assertSee('服務狀態')
            ->assertSee('aria-labelledby="dashboard-marketing-summary"', false)
            ->assertSee('aria-labelledby="dashboard-monitor-summary"', false)
            ->assertSee('aria-labelledby="dashboard-operations-summary"', false)
            ->assertDontSee('最近可讀取的貼文觀看、讚、留言與分享數');

        $responseContent = $response->getContent();
        $summaryPosition = strpos($responseContent, 'id="dashboard-overview"');
        $monitorSummaryPosition = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $monitorListPosition = strpos($responseContent, 'id="dashboard-monitors"');
        $marketingReportsPosition = strpos($responseContent, 'id="dashboard-marketing-summary"');
        $operationsReportsPosition = strpos($responseContent, 'id="dashboard-operations-summary"');

        $this->assertIsInt($summaryPosition);
        $this->assertIsInt($monitorSummaryPosition);
        $this->assertIsInt($monitorListPosition);
        $this->assertIsInt($marketingReportsPosition);
        $this->assertIsInt($operationsReportsPosition);
        $this->assertLessThan($monitorSummaryPosition, $summaryPosition);
        $this->assertLessThan($monitorListPosition, $monitorSummaryPosition);
        $this->assertLessThan($marketingReportsPosition, $monitorListPosition);
        $this->assertLessThan($operationsReportsPosition, $marketingReportsPosition);
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-detail-reports-title"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-monitor-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-marketing-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-operations-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(1, preg_match_all('/<details[^>]*class="dashboard-disclosure dashboard-detail-disclosure"/', $responseContent));
        $this->assertSame(3, preg_match_all('/<details[^>]*class="dashboard-subdisclosure"/', $responseContent));
    }

    public function test_dashboard_opens_filtered_marketing_results_and_keeps_other_sections_collapsed(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_platform' => 'instagram']))
            ->assertOk()
            ->assertSee('已套用篩選')
            ->assertSee('尚無符合條件的貼文可排名');

        $responseContent = $response->getContent();

        $this->assertSame(1, preg_match('/<details[^>]*aria-labelledby="dashboard-detail-reports-title"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(1, preg_match('/<details[^>]*aria-labelledby="dashboard-marketing-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-monitor-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
    }

    public function test_dashboard_shows_a_compact_clear_state_when_monitors_need_no_attention(): void
    {
        $monitor = Monitor::factory()->create([
            'name' => '正常網站',
            'url' => 'https://healthy.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://healthy.example.test/'),
        ]);
        $monitor->forceFill(['last_checked_at' => now()])->save();

        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'normal_status' => 200,
            'scheduled_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('aria-label="待處理 0 項，監測項目 1 項"', false)
            ->assertDontSee('0 項需要處理')
            ->assertSee('目前無待處理項目')
            ->assertSee('新增監測項目')
            ->assertDontSee('dashboard-summary-grid');
    }

    public function test_dashboard_hides_client_filter_until_the_user_has_multiple_clients(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('dashboard-client-filter');

        Client::factory()->for($user)->create(['name' => '第二個客戶']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-client-filter')
            ->assertSee('第二個客戶');
    }

    public function test_dashboard_summary_prioritizes_problem_count_and_links_to_monitor_list(): void
    {
        $healthyMonitor = Monitor::factory()->create([
            'name' => '健康網站',
            'url' => 'https://healthy.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://healthy.example.test/'),
        ]);
        $healthyMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($healthyMonitor)->create([
            'config_version' => $healthyMonitor->config_version,
            'normal_status' => 200,
            'scheduled_at' => now(),
        ]);

        $issueMonitor = Monitor::factory()->create([
            'name' => '異常網站',
            'url' => 'https://issue.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://issue.example.test/'),
        ]);
        $issueMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($issueMonitor)->create([
            'config_version' => $issueMonitor->config_version,
            'normal_status' => 503,
            'error_type' => 'normal_http_status',
            'scheduled_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('class="dashboard-focus-count">1</strong>', false)
            ->assertSee('<span>項</span>', false)
            ->assertDontSee('dashboard-focus-state--')
            ->assertSee('查看全部')
            ->assertDontSee('新增監測項目')
            ->assertSee('異常網站');

        $responseContent = $response->getContent();
        $overviewStart = strpos($responseContent, 'id="dashboard-overview"');
        $detailReportsStart = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $overviewMarkup = substr($responseContent, $overviewStart, $detailReportsStart - $overviewStart);

        $this->assertStringContainsString('查看全部', $overviewMarkup);
        $this->assertStringContainsString('待處理', $overviewMarkup);
        $this->assertStringNotContainsString('優先處理', $overviewMarkup);
        $this->assertStringNotContainsString('項需處理', $overviewMarkup);
        $this->assertStringContainsString('異常網站', $overviewMarkup);
        $this->assertStringNotContainsString('健康網站', $overviewMarkup);
        $this->assertSame(1, $response->viewData('counts')['issue']);
        $this->assertSame(['異常網站'], $response->viewData('attentionMonitors')->pluck('name')->all());
    }

    public function test_dashboard_attention_summary_stays_compact_when_many_monitors_need_review(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (range(1, 4) as $number) {
            $monitor = Monitor::factory()->create([
                'name' => '異常網站 '.$number,
                'url' => 'https://issue-'.$number.'.example.test/',
                'url_hash' => Monitor::fingerprintUrl('https://issue-'.$number.'.example.test/'),
            ]);
            $monitor->forceFill(['last_checked_at' => now()])->save();

            CheckRun::factory()->for($monitor)->create([
                'config_version' => $monitor->config_version,
                'normal_status' => 503,
                'error_type' => 'normal_http_status',
                'scheduled_at' => now(),
            ]);
        }

        $response = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('class="dashboard-focus-count">4</strong>', false)
            ->assertDontSee('新增監測項目')
            ->assertSee('查看全部')
            ->assertDontSee('優先處理');

        $responseContent = $response->getContent();
        $overviewStart = strpos($responseContent, 'id="dashboard-overview"');
        $detailReportsStart = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $overviewMarkup = substr($responseContent, $overviewStart, $detailReportsStart - $overviewStart);

        $this->assertStringContainsString('查看全部', $overviewMarkup);
        $this->assertStringContainsString('異常網站 1', $overviewMarkup);
        $this->assertStringContainsString('異常網站 2', $overviewMarkup);
        $this->assertStringContainsString('異常網站 3', $overviewMarkup);
        $this->assertStringNotContainsString('異常網站 4', $overviewMarkup);
        $this->assertSame(3, $response->viewData('attentionMonitors')->count());
    }
}
