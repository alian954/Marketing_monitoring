<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorResponseTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_time_summary_shows_nearest_rank_p95_for_scheduled_http_runs_from_the_last_day(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create();

        foreach (range(10, 200, 10) as $index => $elapsedMs) {
            $finishedAt = now()->subMinutes($index + 1);
            CheckRun::factory()->for($monitor)->create([
                'source' => 'scheduled',
                'status' => 'completed',
                'scheduled_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'normal_elapsed_ms' => $elapsedMs,
            ]);
        }

        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'finished_at' => now()->subDay()->subSecond(),
            'normal_elapsed_ms' => 999,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'manual',
            'normal_elapsed_ms' => 1000,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'tls_daily',
            'normal_elapsed_ms' => 1001,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'queued',
            'finished_at' => null,
            'normal_elapsed_ms' => 1002,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'normal_elapsed_ms' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('P95 190 毫秒')
            ->assertSee('95% 探測耗時不高於此值');

        $responseTime = $response->viewData('responseTime');
        $this->assertSame(20, $responseTime['count']);
        $this->assertSame(190, $responseTime['p95_ms']);
        $this->assertSame(200, $responseTime['maximum_ms']);
    }

    public function test_response_time_summary_has_no_p95_when_no_http_samples_are_available(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'normal_elapsed_ms' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('尚無 HTTP 探測耗時');

        $responseTime = $response->viewData('responseTime');
        $this->assertSame(0, $responseTime['count']);
        $this->assertNull($responseTime['p95_ms']);
    }
}
