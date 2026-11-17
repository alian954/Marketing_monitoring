<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneMonitoringDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_removes_only_expired_completed_runs_and_keeps_incident_history(): void
    {
        $monitor = Monitor::factory()->create();
        $oldRun = CheckRun::factory()->for($monitor)->create([
            'scheduled_at' => now()->subDays(31),
            'finished_at' => now()->subDays(31),
        ]);
        $recentRun = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        $event = IncidentEvent::factory()->create([
            'incident_id' => $incident->id,
            'check_run_id' => $oldRun->id,
        ]);

        $this->artisan('monitors:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('已清除');

        $this->assertDatabaseMissing('check_runs', ['id' => $oldRun->id]);
        $this->assertDatabaseHas('check_runs', ['id' => $recentRun->id]);
        $this->assertDatabaseHas('incidents', ['id' => $incident->id]);
        $this->assertDatabaseHas('incident_events', [
            'id' => $event->id,
            'incident_id' => $incident->id,
            'check_run_id' => null,
        ]);
    }
}
