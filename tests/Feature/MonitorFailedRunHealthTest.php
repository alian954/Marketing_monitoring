<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorFailedRunHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_failed_check_is_counted_as_a_warning_on_the_dashboard(): void
    {
        $monitor = Monitor::factory()->create(['last_checked_at' => now()]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'failed',
            'scheduled_at' => now(),
            'finished_at' => now(),
            'normal_status' => null,
            'error_type' => 'queue_job_failed',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('queue_job_failed')
            ->assertSee('警告');

        $this->assertSame('warning', $response->viewData('monitors')->first()->health_state);
        $this->assertSame(1, $response->viewData('counts')['warning']);
    }

    public function test_incomplete_check_run_remains_pending(): void
    {
        $monitor = Monitor::factory()->create(['last_checked_at' => null]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'queued',
            'started_at' => null,
            'finished_at' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame('pending', $response->viewData('monitors')->first()->health_state);
    }

    public function test_indeterminate_content_check_is_a_warning_instead_of_a_site_outage(): void
    {
        $monitor = Monitor::factory()->create(['last_checked_at' => now()]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => json_encode([
                'accepted_status_codes' => [],
                'expected_text' => 'Campaign headline',
                'expected_text_mode' => 'present',
            ], JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now(),
            'finished_at' => now(),
            'normal_status' => 200,
            'error_type' => 'body_too_large',
            'expected_text_found' => null,
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'probe_incomplete',
            'severity' => 'warning',
            'state' => Incident::STATE_OPEN,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('警告')
            ->assertSee('文字驗證未完成');

        $this->assertSame('warning', $response->viewData('monitors')->first()->health_state);
    }

    public function test_stale_monitor_remains_stale_after_a_recent_failed_attempt(): void
    {
        $monitor = Monitor::factory()->create(['last_checked_at' => now()->subMinutes(30)]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'failed',
            'scheduled_at' => now(),
            'finished_at' => now(),
            'normal_status' => null,
            'error_type' => 'queue_job_failed',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame('stale', $response->viewData('monitors')->first()->health_state);
        $this->assertSame(1, $response->viewData('counts')['stale']);
    }
}
