<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorResponseBodySizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_body_size_chart_summarizes_scheduled_http_runs_from_the_last_day(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create();

        foreach ([100, 200, 300, 400] as $index => $bodyBytes) {
            $finishedAt = now()->subMinutes($index + 1);
            CheckRun::factory()->for($monitor)->create([
                'source' => 'scheduled',
                'status' => 'completed',
                'scheduled_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'normal_status' => 200,
                'response_body_bytes' => $bodyBytes,
            ]);
        }

        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'finished_at' => now()->subDay()->subSecond(),
            'response_body_bytes' => 900,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'manual',
            'response_body_bytes' => 1000,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'tls_daily',
            'response_body_bytes' => 1100,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'queued',
            'finished_at' => null,
            'response_body_bytes' => 1200,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'response_body_bytes' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('HTTP 回應大小趨勢')
            ->assertSee('P95 400 bytes');

        $responseBodySize = $response->viewData('responseBodySize');
        $this->assertSame(4, $responseBodySize['count']);
        $this->assertSame(250, $responseBodySize['average_bytes']);
        $this->assertSame(100, $responseBodySize['minimum_bytes']);
        $this->assertSame(400, $responseBodySize['maximum_bytes']);
        $this->assertSame(400, $responseBodySize['p95_bytes']);
        $this->assertSame([400, 300, 200, 100], array_column($responseBodySize['points'], 'body_bytes'));
    }

    public function test_response_body_size_chart_shows_an_empty_state_without_complete_recent_samples(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'response_body_bytes' => null,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'finished_at' => now()->subDay()->subSecond(),
            'response_body_bytes' => 1024,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('尚無完整的 HTTP 回應大小')
            ->assertSee('截斷或無法完整讀取的回應不列入統計');

        $responseBodySize = $response->viewData('responseBodySize');
        $this->assertSame(0, $responseBodySize['count']);
        $this->assertNull($responseBodySize['p95_bytes']);
    }
}
