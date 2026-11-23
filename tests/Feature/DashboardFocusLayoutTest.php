<?php

namespace Tests\Feature;

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
            ->assertSee(route('incidents.index'), false)
            ->assertSee(route('monitors.index'), false)
            ->assertSee(route('features.index'), false)
            ->assertSee('進行中的事件')
            ->assertSee('監測項目')
            ->assertSee('社群貼文')
            ->assertSee('行銷數據')
            ->assertSee('服務品質與維運')
            ->assertSee('aria-labelledby="dashboard-marketing-summary"', false)
            ->assertSee('aria-labelledby="dashboard-operations-summary"', false)
            ->assertDontSee('最近可讀取的貼文觀看、讚、留言與分享數');

        $responseContent = $response->getContent();
        $monitorListPosition = strpos($responseContent, 'id="dashboard-monitors"');
        $marketingReportsPosition = strpos($responseContent, 'id="dashboard-marketing-summary"');
        $operationsReportsPosition = strpos($responseContent, 'id="dashboard-operations-summary"');

        $this->assertIsInt($monitorListPosition);
        $this->assertIsInt($marketingReportsPosition);
        $this->assertIsInt($operationsReportsPosition);
        $this->assertLessThan($marketingReportsPosition, $monitorListPosition);
        $this->assertLessThan($operationsReportsPosition, $marketingReportsPosition);
        $this->assertSame(0, preg_match('/<details[^>]*aria-labelledby="dashboard-marketing-summary"[^>]*\bopen\b[^>]*>/', $responseContent));
    }
}
