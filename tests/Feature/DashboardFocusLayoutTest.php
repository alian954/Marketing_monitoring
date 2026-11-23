<?php

namespace Tests\Feature;

use App\Models\CheckRun;
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
            ->assertSee('aria-label="監測項目 1 項"', false)
            ->assertSee('aria-label="行銷活動 1 項"', false);
    }

    public function test_dashboard_shows_key_metrics_and_collapses_detailed_reports_when_no_items_need_attention(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<h1 class="page-title">監測總覽</h1>', false)
            ->assertSee('<section class="stat-grid dashboard-summary-grid spaced-top" aria-label="總覽重點">', false)
            ->assertSee('aria-label="需要處理 0 項"', false)
            ->assertSee('aria-label="監測項目 0 項"', false)
            ->assertSee('aria-label="行銷活動 0 項"', false)
            ->assertDontSee('dashboard-focus-grid')
            ->assertDontSee('id="dashboard-attention"', false)
            ->assertSee(route('incidents.index'), false)
            ->assertSee(route('monitors.index'), false)
            ->assertSee(route('features.index'), false)
            ->assertSee('需要處理')
            ->assertSee('進行中的事件')
            ->assertSee('監測項目')
            ->assertSee('行銷活動')
            ->assertSee('行銷數據')
            ->assertSee('監測清單與執行狀態')
            ->assertSee('服務品質與維運')
            ->assertSee('aria-labelledby="dashboard-marketing-summary"', false)
            ->assertSee('aria-labelledby="dashboard-monitor-summary"', false)
            ->assertSee('aria-labelledby="dashboard-operations-summary"', false)
            ->assertDontSee('最近可讀取的貼文觀看、讚、留言與分享數');

        $responseContent = $response->getContent();
        $summaryPosition = strpos($responseContent, 'aria-label="總覽重點"');
        $monitorListPosition = strpos($responseContent, 'id="dashboard-monitors"');
        $marketingReportsPosition = strpos($responseContent, 'id="dashboard-marketing-summary"');
        $operationsReportsPosition = strpos($responseContent, 'id="dashboard-operations-summary"');

        $this->assertIsInt($summaryPosition);
        $this->assertIsInt($monitorListPosition);
        $this->assertIsInt($marketingReportsPosition);
        $this->assertIsInt($operationsReportsPosition);
        $this->assertLessThan($monitorListPosition, $summaryPosition);
        $this->assertLessThan($marketingReportsPosition, $monitorListPosition);
        $this->assertLessThan($operationsReportsPosition, $marketingReportsPosition);
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-monitor-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-marketing-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-operations-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
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
            ->assertSee('aria-label="需要處理 1 項"', false)
            ->assertSee('健康網站');

        $responseContent = $response->getContent();
        $attentionStart = strpos($responseContent, 'id="dashboard-attention"');
        $collapsedMonitorStart = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $attentionMarkup = substr($responseContent, $attentionStart, $collapsedMonitorStart - $attentionStart);

        $this->assertStringContainsString('異常網站', $attentionMarkup);
        $this->assertStringNotContainsString('健康網站', $attentionMarkup);
        $this->assertSame(1, $response->viewData('counts')['issue']);
    }

    public function test_dashboard_attention_list_shows_only_three_items_and_reports_the_remaining_count(): void
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
            ->assertSee('aria-label="需要處理 4 項"', false)
            ->assertSee('另有 1 項');

        $responseContent = $response->getContent();
        $attentionStart = strpos($responseContent, 'id="dashboard-attention"');
        $collapsedMonitorStart = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $attentionMarkup = substr($responseContent, $attentionStart, $collapsedMonitorStart - $attentionStart);

        $this->assertStringContainsString('異常網站 1', $attentionMarkup);
        $this->assertStringContainsString('異常網站 2', $attentionMarkup);
        $this->assertStringContainsString('異常網站 3', $attentionMarkup);
        $this->assertStringNotContainsString('異常網站 4', $attentionMarkup);
    }
}
