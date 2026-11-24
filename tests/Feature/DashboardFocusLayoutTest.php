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

    public function test_dashboard_summary_counts_monitors_and_campaign_targets(): void
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

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="待處理 0 項，監測項目 1 項"', false)
            ->assertSee('1 個活動 · 互動與投放');
    }

    public function test_dashboard_shows_key_metrics_and_collapses_detailed_reports_when_no_items_need_attention(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<h1 class="page-title">監測總覽</h1>', false)
            ->assertSee('aria-label="待處理 0 項，監測項目 0 項"', false)
            ->assertSee('尚未新增監測項目')
            ->assertDontSee('dashboard-focus-grid')
            ->assertDontSee('dashboard-summary-grid')
            ->assertSee(route('incidents.index'), false)
            ->assertSee(route('monitors.index'), false)
            ->assertSee(route('features.index'), false)
            ->assertSee('待處理項目')
            ->assertSee('詳細報表')
            ->assertSee('進行中的事件')
            ->assertSee('監測項目')
            ->assertSee('0 個活動 · 互動與投放')
            ->assertSee('行銷數據')
            ->assertSee('監測清單與執行狀態')
            ->assertSee('服務品質與維運')
            ->assertSee('aria-labelledby="dashboard-marketing-summary"', false)
            ->assertSee('aria-labelledby="dashboard-monitor-summary"', false)
            ->assertSee('aria-labelledby="dashboard-operations-summary"', false)
            ->assertDontSee('最近可讀取的貼文觀看、讚、留言與分享數');

        $responseContent = $response->getContent();
        $summaryPosition = strpos($responseContent, 'id="dashboard-attention"');
        $detailReportsPosition = strpos($responseContent, 'id="dashboard-detail-reports-title"');
        $monitorListPosition = strpos($responseContent, 'id="dashboard-monitors"');
        $marketingReportsPosition = strpos($responseContent, 'id="dashboard-marketing-summary"');
        $operationsReportsPosition = strpos($responseContent, 'id="dashboard-operations-summary"');

        $this->assertIsInt($summaryPosition);
        $this->assertIsInt($detailReportsPosition);
        $this->assertIsInt($monitorListPosition);
        $this->assertIsInt($marketingReportsPosition);
        $this->assertIsInt($operationsReportsPosition);
        $this->assertLessThan($detailReportsPosition, $summaryPosition);
        $this->assertLessThan($monitorListPosition, $detailReportsPosition);
        $this->assertLessThan($marketingReportsPosition, $monitorListPosition);
        $this->assertLessThan($operationsReportsPosition, $marketingReportsPosition);
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-detail-reports-title"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-monitor-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-marketing-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-operations-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
    }

    public function test_dashboard_opens_detailed_reports_when_marketing_filters_are_applied(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_platform' => 'instagram']))
            ->assertOk();

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
            ->assertSee('aria-label="待處理 0 項，監測項目 1 項"', false)
            ->assertSee('0 項需要處理')
            ->assertDontSee('無待處理')
            ->assertSee('目前沒有需要處理的項目。')
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

    public function test_dashboard_attention_list_shows_problem_monitors_before_the_collapsed_full_list(): void
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
            ->assertSee('異常網站')
            ->assertSee('aria-label="待處理 1 項，監測項目 2 項"', false)
            ->assertSee('健康網站');

        $responseContent = $response->getContent();
        $attentionStart = strpos($responseContent, 'id="dashboard-attention"');
        $collapsedMonitorStart = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $attentionMarkup = substr($responseContent, $attentionStart, $collapsedMonitorStart - $attentionStart);

        $this->assertStringContainsString('異常網站', $attentionMarkup);
        $this->assertStringNotContainsString('健康網站', $attentionMarkup);
        $this->assertSame(1, $response->viewData('counts')['issue']);
    }

    public function test_dashboard_attention_list_shows_the_top_item_and_one_link_to_the_remaining_work(): void
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
            ->assertSee('aria-label="待處理 4 項，監測項目 4 項"', false)
            ->assertSee('還有 3 項需處理');

        $responseContent = $response->getContent();
        $attentionStart = strpos($responseContent, 'id="dashboard-attention"');
        $collapsedMonitorStart = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $attentionMarkup = substr($responseContent, $attentionStart, $collapsedMonitorStart - $attentionStart);

        $this->assertStringContainsString('異常網站 1', $attentionMarkup);
        $this->assertStringNotContainsString('異常網站 2', $attentionMarkup);
        $this->assertSame(1, substr_count($attentionMarkup, 'dashboard-attention-more'));
    }
}
