<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFocusLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_prioritizes_key_actions_and_collapses_detailed_reports(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<h1 class="page-title">監測總覽</h1>', false)
            ->assertSee('<section class="dashboard-focus-grid" aria-label="快速摘要">', false)
            ->assertSee('<section class="panel dashboard-attention-panel" id="dashboard-attention"', false)
            ->assertSee(route('incidents.index'), false)
            ->assertSee(route('monitors.index'), false)
            ->assertSee(route('features.index'), false)
            ->assertSee('需要處理')
            ->assertSee('進行中的事件')
            ->assertSee('監測項目')
            ->assertSee('行銷數據')
            ->assertSee('監測清單與執行狀態')
            ->assertSee('服務品質與維運')
            ->assertSee('目前沒有需要處理的項目')
            ->assertSee('aria-labelledby="dashboard-marketing-summary"', false)
            ->assertSee('aria-labelledby="dashboard-monitor-summary"', false)
            ->assertSee('aria-labelledby="dashboard-operations-summary"', false)
            ->assertDontSee('最近可讀取的貼文觀看、讚、留言與分享數');

        $responseContent = $response->getContent();
        $attentionPosition = strpos($responseContent, 'id="dashboard-attention"');
        $monitorListPosition = strpos($responseContent, 'id="dashboard-monitors"');
        $marketingReportsPosition = strpos($responseContent, 'id="dashboard-marketing-summary"');
        $operationsReportsPosition = strpos($responseContent, 'id="dashboard-operations-summary"');

        $this->assertIsInt($attentionPosition);
        $this->assertIsInt($monitorListPosition);
        $this->assertIsInt($marketingReportsPosition);
        $this->assertIsInt($operationsReportsPosition);
        $this->assertLessThan($monitorListPosition, $attentionPosition);
        $this->assertLessThan($marketingReportsPosition, $monitorListPosition);
        $this->assertLessThan($operationsReportsPosition, $marketingReportsPosition);
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-monitor-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-marketing-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
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
            ->assertSee('健康網站');

        $responseContent = $response->getContent();
        $attentionStart = strpos($responseContent, 'id="dashboard-attention"');
        $collapsedMonitorStart = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $attentionMarkup = substr($responseContent, $attentionStart, $collapsedMonitorStart - $attentionStart);

        $this->assertStringContainsString('異常網站', $attentionMarkup);
        $this->assertStringNotContainsString('健康網站', $attentionMarkup);
        $this->assertSame(1, $response->viewData('counts')['issue']);
    }
}
